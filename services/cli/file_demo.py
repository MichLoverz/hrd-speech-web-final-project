"""
Same pipeline as mic_demo.py, but the audio comes from a WAV file that is
fed to the streamer at real-time pace (a chunk every --chunk-ms). Use it to
reproduce a run exactly, compare model sizes on identical audio, or test
without a microphone.

    python cli/file_demo.py path/to/clip.wav
    python cli/file_demo.py clip.wav --model medium --label e5-medium
    python cli/file_demo.py clip.wav --fast          # no pacing: as fast as the CPU allows

Any sample rate / channel count is accepted; the file is converted to
16 kHz mono first. Latency numbers are only meaningful with pacing on.
"""

from __future__ import annotations

import argparse
import time
from pathlib import Path

import numpy as np
import soundfile as sf

from _common import TerminalRenderer, add_common_args, config_from_args, finish, load_engines, read_prompt
from app.asr.metrics import RunRecorder
from app.asr.streamer import StreamingTranscriber
from app.config import SAMPLE_RATE


def load_mono_16k(path: Path) -> np.ndarray:
    audio, sr = sf.read(path, dtype="float32", always_2d=True)
    audio = audio.mean(axis=1)                       # downmix to mono
    if sr != SAMPLE_RATE:
        # Linear-interpolation resample: adequate for speech; ffmpeg is used for evaluation sets.
        n_out = int(len(audio) * SAMPLE_RATE / sr)
        x_old = np.linspace(0, 1, len(audio), endpoint=False)
        x_new = np.linspace(0, 1, n_out, endpoint=False)
        audio = np.interp(x_new, x_old, audio).astype(np.float32)
    return audio


def main() -> None:
    p = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    add_common_args(p)
    p.add_argument("wav", type=Path)
    p.add_argument("--fast", action="store_true", help="do not pace to real time")
    a = p.parse_args()

    audio = load_mono_16k(a.wav)
    duration = len(audio) / SAMPLE_RATE
    print(f"Berkas: {a.wav.name}  ({duration:.1f} s)")

    config = config_from_args(a)
    engine, partial_engine = load_engines(config)
    recorder = RunRecorder(config, label=a.label)
    streamer = StreamingTranscriber(engine, config, on_event=TerminalRenderer(recorder),
                                    partial_engine=partial_engine, initial_prompt=read_prompt(a))

    chunk = int(SAMPLE_RATE * a.chunk_ms / 1000)
    started = time.time()
    next_due = started
    for i in range(0, len(audio), chunk):
        if not a.fast:
            # Pace: chunk i "arrives" at next_due, exactly when it would have arrived live.
            # If inference blocked us past that time, the chunk is stamped with its
            # scheduled arrival, so the queueing delay counts against latency.
            next_due += a.chunk_ms / 1000
            delay = next_due - time.time()
            if delay > 0:
                time.sleep(delay)
            streamer.feed(audio[i:i + chunk], received_at=next_due)
        else:
            streamer.feed(audio[i:i + chunk])
    streamer.flush()

    wall = round(time.time() - started, 1)
    print(f"\nSelesai: audio {duration:.1f} s diproses dalam {wall} s (wall)")
    finish(recorder, streamer, a, extra={"wall_seconds": wall, "audio_seconds": round(duration, 1),
                                         "paced": not a.fast, "source": a.wav.name})


if __name__ == "__main__":
    main()
