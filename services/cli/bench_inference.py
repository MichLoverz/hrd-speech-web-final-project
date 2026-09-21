"""
Micro-benchmark for one transcribe() call: how inference time depends on
audio length, model size and CPU threads. Used to choose streaming
parameters (ADR-005). Not an accuracy test.

    python cli/bench_inference.py clip.wav --models base small --threads 0 6 12
"""

from __future__ import annotations

import argparse
import sys
import time
from pathlib import Path

import numpy as np

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))
from app.asr.engine import WhisperEngine       # noqa: E402
from app.config import AsrConfig, SAMPLE_RATE  # noqa: E402
from file_demo import load_mono_16k            # noqa: E402


def main() -> None:
    p = argparse.ArgumentParser()
    p.add_argument("wav", type=Path)
    p.add_argument("--models", nargs="+", default=["base", "small"])
    p.add_argument("--threads", nargs="+", type=int, default=[0, 6])
    p.add_argument("--lengths", nargs="+", type=float, default=[1, 2, 4, 8, 15])
    p.add_argument("--repeat", type=int, default=2)
    p.add_argument("--language", default="en")
    a = p.parse_args()

    audio = load_mono_16k(a.wav)
    print(f"{'model':<8}{'threads':<9}{'len(s)':<8}{'infer(ms)':<11}{'RTF':<7}text")
    for model in a.models:
        for threads in a.threads:
            cfg = AsrConfig(model_size=model, cpu_threads=threads, language=a.language)
            engine = WhisperEngine(cfg)
            engine.transcribe(audio[:SAMPLE_RATE * 2], final=False)   # warm-up, not measured
            for length in a.lengths:
                clip = audio[: int(length * SAMPLE_RATE)]
                times, text = [], ""
                for _ in range(a.repeat):
                    r = engine.transcribe(clip, final=False)
                    times.append(r.inference_ms)
                    text = r.text
                ms = int(np.median(times))
                print(f"{model:<8}{threads:<9}{length:<8}{ms:<11}{ms / (length * 1000):<7.2f}{text[:60]}")
            del engine


if __name__ == "__main__":
    main()
