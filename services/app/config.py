"""
Tunable parameters for the streaming ASR pipeline.

Everything that affects latency or accuracy lives here so an experiment run
can be described completely by dumping this object (see cli/mic_demo.py).
"""

from __future__ import annotations

import os
from dataclasses import dataclass, asdict, field
from pathlib import Path

SERVICES_DIR = Path(__file__).resolve().parent.parent
MODELS_DIR = SERVICES_DIR / "models"          # git-ignored; CTranslate2 weights are downloaded here

SAMPLE_RATE = 16_000                           # Whisper was trained on 16 kHz mono
VAD_FRAME_SAMPLES = 512                        # Silero VAD v5 works on 512-sample (32 ms) frames at 16 kHz


@dataclass
class AsrConfig:
    # --- model ---
    model_size: str = "small"                  # tiny | base | small | medium | large-v3 - used for FINAL transcripts
    partial_model_size: str | None = "base"    # smaller model for PARTIALS (None = same model as finals).
                                               # Whisper pads every input to 30 s, so each call has a fixed cost:
                                               # measured 2026-09-22 on Ryzen 5 6600H: base ~0.6 s, small ~1.8 s.
    compute_type: str = "int8"                 # int8 is the CPU sweet spot: ~4x smaller, 2-4x faster than float32
    device: str = "cpu"
    cpu_threads: int = os.cpu_count() or 4     # CTranslate2 defaults to 4; all logical cores was ~35% faster here
    language: str | None = "id"                # None = auto-detect per window (slower, less stable)

    # --- decoding ---
    partial_beam_size: int = 1                 # greedy for partials: speed matters more than the last few % accuracy
    final_beam_size: int = 2                   # a bit more search for the text that is actually stored
    condition_on_previous_text: bool = False   # avoids the model "continuing" a hallucinated sentence across windows

    # --- streaming ---
    partial_interval_ms: int = 1200            # how often a partial transcript is attempted while speech is ongoing
    partial_window_s: float = 12.0             # partials re-transcribe at most the last N seconds of the current segment
    min_speech_ms_for_partial: int = 600       # don't bother transcribing a segment shorter than this
    max_segment_s: float = 25.0                # force a final before Whisper's 30 s window is exceeded

    # --- VAD ---
    vad_threshold: float = 0.5                 # speech probability above this = speech
    vad_min_silence_ms: int = 500              # silence longer than this ends a segment -> final transcript
    vad_speech_pad_ms: int = 200               # keep a little audio before speech onset so first syllables aren't cut

    def to_dict(self) -> dict:
        return asdict(self)


DEFAULT = AsrConfig()
