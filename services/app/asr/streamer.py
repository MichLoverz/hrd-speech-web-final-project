"""
Streaming wrapper that turns a non-streaming model (Whisper) into a
real-time transcriber.

How it works
------------
Audio arrives in small chunks (100-250 ms). Every chunk is:
  1. appended to the current *segment buffer* (audio since speech started),
  2. run through the frame-level VAD to update speech / silence state.

Two kinds of transcript are emitted:
  * PARTIAL  - every `partial_interval_ms` while speech is ongoing, we
               transcribe the tail of the segment buffer (at most
               `partial_window_s`) and emit is_final=False. The text may
               change on the next partial because the model now has more
               context. Partials are *skipped* if the previous inference is
               still running, so a slow CPU degrades update rate, never
               builds a backlog.
  * FINAL    - when the VAD sees silence longer than `vad_min_silence_ms`
               (or the segment reaches `max_segment_s`), the whole segment
               is transcribed once more with a larger beam and emitted with
               is_final=True. The buffer is then cleared.

Every emit carries the timestamps needed for the latency chapter:
  chunk_received_at, inference_started_at, inference_ended_at, emitted_at.
"""

from __future__ import annotations

import time
from dataclasses import dataclass, field
from typing import Callable

import numpy as np

from app.asr.engine import WhisperEngine
from app.asr.vad import StreamingVad
from app.config import AsrConfig, SAMPLE_RATE, VAD_FRAME_SAMPLES


@dataclass
class TranscriptEvent:
    text: str
    is_final: bool
    start_ms: int                      # position of the segment in the stream
    end_ms: int
    chunk_received_at: float           # wall-clock seconds (time.time())
    inference_started_at: float
    inference_ended_at: float
    emitted_at: float
    audio_duration_ms: int             # length of the window that was transcribed
    real_time_factor: float
    model: str
    avg_logprob: float | None = None
    no_speech_prob: float | None = None
    seq: int = 0

    @property
    def latency_ms(self) -> int:
        """Time from the last audio chunk arriving to the transcript leaving."""
        return int((self.emitted_at - self.chunk_received_at) * 1000)

    @property
    def inference_ms(self) -> int:
        return int((self.inference_ended_at - self.inference_started_at) * 1000)

    def to_row(self) -> dict:
        return {
            "seq": self.seq,
            "is_final": int(self.is_final),
            "start_ms": self.start_ms,
            "end_ms": self.end_ms,
            "audio_duration_ms": self.audio_duration_ms,
            "chunk_received_at": round(self.chunk_received_at, 3),
            "inference_started_at": round(self.inference_started_at, 3),
            "inference_ended_at": round(self.inference_ended_at, 3),
            "emitted_at": round(self.emitted_at, 3),
            "latency_ms": self.latency_ms,
            "inference_ms": self.inference_ms,
            "real_time_factor": self.real_time_factor,
            "avg_logprob": None if self.avg_logprob is None else round(self.avg_logprob, 3),
            "no_speech_prob": None if self.no_speech_prob is None else round(self.no_speech_prob, 3),
            "model": self.model,
            "text": self.text,
        }


@dataclass
class _Segment:
    """Audio and bookkeeping for the utterance currently being spoken."""
    audio: list[np.ndarray] = field(default_factory=list)
    n_samples: int = 0
    start_ms: int = 0
    has_speech: bool = False
    speech_frames: int = 0
    silence_ms_run: int = 0            # consecutive silence since the last speech frame
    last_chunk_at: float = 0.0
    last_partial_at: float = 0.0

    def concat(self) -> np.ndarray:
        return np.concatenate(self.audio) if self.audio else np.zeros(0, dtype=np.float32)


class StreamingTranscriber:
    def __init__(
        self,
        engine: WhisperEngine,
        config: AsrConfig,
        on_event: Callable[[TranscriptEvent], None],
        vad: StreamingVad | None = None,
        partial_engine: WhisperEngine | None = None,
    ):
        self.engine = engine                                 # finals (the text that gets stored)
        self.partial_engine = partial_engine or engine       # partials (may be a smaller, faster model)
        self.config = config
        self.on_event = on_event
        self.vad = vad or StreamingVad()

        self._stream_ms = 0                # audio time consumed so far
        self._frame_tail = np.zeros(0, dtype=np.float32)   # leftover samples < one VAD frame
        self._pre_roll: list[np.ndarray] = []              # recent silence kept for speech_pad
        self._seg = _Segment()
        self._busy = False
        self._seq = 0
        self.stats = {"partials": 0, "partials_skipped": 0, "finals": 0}

    # ------------------------------------------------------------------ input

    def feed(self, chunk: np.ndarray, received_at: float | None = None) -> None:
        """
        chunk: float32 mono 16 kHz (any length; typically 100-250 ms).
        received_at: wall-clock time the chunk *arrived* (mic callback / socket
        receive). Pass it explicitly when feeding from a queue, otherwise a
        chunk that waited behind a long inference would be stamped late and
        the reported latency would be optimistic.
        Runs VAD, updates the segment, and may call on_event() synchronously.
        """
        if received_at is None:
            received_at = time.time()
        chunk = chunk.astype(np.float32, copy=False)
        chunk_ms = int(len(chunk) / SAMPLE_RATE * 1000)

        speech_prob_max = self._run_vad(chunk)
        is_speech = speech_prob_max >= self.config.vad_threshold

        if is_speech:
            if not self._seg.has_speech:
                self._start_segment(received_at)
            self._seg.has_speech = True
            self._seg.speech_frames += 1
            self._seg.silence_ms_run = 0
        else:
            self._seg.silence_ms_run += chunk_ms

        if self._seg.has_speech:
            self._seg.audio.append(chunk)
            self._seg.n_samples += len(chunk)
            self._seg.last_chunk_at = received_at
        else:
            self._keep_pre_roll(chunk)

        self._stream_ms += chunk_ms

        if not self._seg.has_speech:
            return

        seg_ms = self._seg.n_samples / SAMPLE_RATE * 1000
        silence_ended = self._seg.silence_ms_run >= self.config.vad_min_silence_ms
        too_long = seg_ms >= self.config.max_segment_s * 1000

        if silence_ended or too_long:
            self._emit_final()
        elif (time.time() - self._seg.last_partial_at) * 1000 >= self.config.partial_interval_ms:
            self._emit_partial()

    def flush(self) -> None:
        """Call when the input ends (stop button, file EOF) to get the last final."""
        if self._seg.has_speech and self._seg.speech_frames > 0:
            self._emit_final()

    # -------------------------------------------------------------- internals

    def _run_vad(self, chunk: np.ndarray) -> float:
        """Feed whole 512-sample frames to the VAD; keep the remainder for next time."""
        buf = np.concatenate([self._frame_tail, chunk]) if len(self._frame_tail) else chunk
        n_frames = len(buf) // VAD_FRAME_SAMPLES
        max_prob = 0.0
        for i in range(n_frames):
            frame = buf[i * VAD_FRAME_SAMPLES:(i + 1) * VAD_FRAME_SAMPLES]
            max_prob = max(max_prob, self.vad.probability(frame))
        self._frame_tail = buf[n_frames * VAD_FRAME_SAMPLES:]
        return max_prob

    def _keep_pre_roll(self, chunk: np.ndarray) -> None:
        self._pre_roll.append(chunk)
        keep = int(self.config.vad_speech_pad_ms / 1000 * SAMPLE_RATE)
        total = sum(len(c) for c in self._pre_roll)
        while self._pre_roll and total - len(self._pre_roll[0]) >= keep:
            total -= len(self._pre_roll.pop(0))

    def _start_segment(self, now: float) -> None:
        pre = self._pre_roll
        pre_samples = sum(len(c) for c in pre)
        self._seg = _Segment(
            audio=list(pre),
            n_samples=pre_samples,
            start_ms=max(0, self._stream_ms - int(pre_samples / SAMPLE_RATE * 1000)),
            last_partial_at=now,
        )
        self._pre_roll = []

    def _emit_partial(self) -> None:
        seg_ms = self._seg.n_samples / SAMPLE_RATE * 1000
        if seg_ms < self.config.min_speech_ms_for_partial:
            return
        if self._busy:
            # Never queue partials: a late partial is worthless and only adds backlog.
            self.stats["partials_skipped"] += 1
            return

        self._busy = True
        try:
            audio = self._seg.concat()
            window = int(self.config.partial_window_s * SAMPLE_RATE)
            if len(audio) > window:
                audio = audio[-window:]
            result = self.partial_engine.transcribe(audio, final=False)
            self._seg.last_partial_at = time.time()
            self.stats["partials"] += 1
            self._publish(result, audio_len=len(audio), is_final=False, model=self.partial_engine.name)
        finally:
            self._busy = False

    def _emit_final(self) -> None:
        seg = self._seg
        self._seg = _Segment()
        self._pre_roll = []
        if seg.speech_frames == 0:
            return

        audio = seg.concat()
        self._busy = True
        try:
            result = self.engine.transcribe(audio, final=True)
            self.stats["finals"] += 1
            self._publish(result, audio_len=len(audio), is_final=True, seg=seg)
        finally:
            self._busy = False

    def _publish(self, result, *, audio_len: int, is_final: bool, seg: _Segment | None = None,
                 model: str | None = None) -> None:
        seg = seg or self._seg
        self._seq += 1
        event = TranscriptEvent(
            text=result.text,
            is_final=is_final,
            start_ms=seg.start_ms,
            end_ms=seg.start_ms + int(seg.n_samples / SAMPLE_RATE * 1000),
            chunk_received_at=seg.last_chunk_at,
            inference_started_at=result.inference_started_at,
            inference_ended_at=result.inference_ended_at,
            emitted_at=time.time(),
            audio_duration_ms=int(audio_len / SAMPLE_RATE * 1000),
            real_time_factor=result.real_time_factor,
            model=model or self.engine.name,
            avg_logprob=result.avg_logprob,
            no_speech_prob=result.no_speech_prob,
            seq=self._seq,
        )
        self.on_event(event)
