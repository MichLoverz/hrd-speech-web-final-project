"""
Thin wrapper around faster-whisper.

Whisper itself is not a streaming model: it takes up to 30 s of audio and
returns text for all of it. Streaming is built *around* it in streamer.py.
This module only knows how to turn one array of audio into text, and how
long that took.
"""

from __future__ import annotations

import os
import time
from dataclasses import dataclass

# HF cache uses symlinks; on Windows without Developer Mode it falls back to copies
# and prints a long warning twice per run. Harmless, so silence it.
os.environ.setdefault("HF_HUB_DISABLE_SYMLINKS_WARNING", "1")

import numpy as np
from faster_whisper import WhisperModel

from app.config import AsrConfig, MODELS_DIR, SAMPLE_RATE


@dataclass
class Transcription:
    text: str
    inference_started_at: float       # time.time() seconds, wall clock
    inference_ended_at: float
    audio_duration_ms: int
    avg_logprob: float | None         # mean token log-probability; very low values often mean garbage
    no_speech_prob: float | None      # model's own estimate that the window is silence

    @property
    def inference_ms(self) -> int:
        return int((self.inference_ended_at - self.inference_started_at) * 1000)

    @property
    def real_time_factor(self) -> float:
        """inference time / audio duration. Below 1.0 means faster than real time."""
        if self.audio_duration_ms == 0:
            return 0.0
        return round(self.inference_ms / self.audio_duration_ms, 3)


class WhisperEngine:
    def __init__(self, config: AsrConfig, model_size: str | None = None):
        """model_size overrides config.model_size (used to build the smaller partial engine)."""
        self.config = config
        self.model_size = model_size or config.model_size
        MODELS_DIR.mkdir(parents=True, exist_ok=True)
        t0 = time.time()
        self.model = WhisperModel(
            self.model_size,
            device=config.device,
            compute_type=config.compute_type,
            cpu_threads=config.cpu_threads,
            download_root=str(MODELS_DIR),
        )
        self.load_seconds = round(time.time() - t0, 2)

    @property
    def name(self) -> str:
        return f"faster-whisper/{self.model_size}/{self.config.compute_type}"

    def transcribe(self, audio: np.ndarray, *, final: bool) -> Transcription:
        """
        audio: float32 mono at 16 kHz, values in [-1, 1].
        final=True uses the larger beam size; partials use greedy decoding.
        """
        if audio.dtype != np.float32:
            audio = audio.astype(np.float32)

        started = time.time()
        segments, _info = self.model.transcribe(
            audio,
            language=self.config.language,
            beam_size=self.config.final_beam_size if final else self.config.partial_beam_size,
            condition_on_previous_text=self.config.condition_on_previous_text,
            vad_filter=False,            # we gate with our own frame-level VAD in streamer.py
            without_timestamps=True,     # word timestamps cost time; segment position comes from the streamer
        )
        # transcribe() is lazy: decoding only happens while iterating.
        pieces, logprobs, no_speech = [], [], []
        for seg in segments:
            pieces.append(seg.text.strip())
            logprobs.append(seg.avg_logprob)
            no_speech.append(seg.no_speech_prob)
        ended = time.time()

        return Transcription(
            text=" ".join(p for p in pieces if p),
            inference_started_at=started,
            inference_ended_at=ended,
            audio_duration_ms=int(len(audio) / SAMPLE_RATE * 1000),
            avg_logprob=float(np.mean(logprobs)) if logprobs else None,
            no_speech_prob=float(np.max(no_speech)) if no_speech else None,
        )
