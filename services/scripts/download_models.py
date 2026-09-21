"""Download the non-HuggingFace models this service needs (faster-whisper fetches its own)."""
from __future__ import annotations

import sys
import urllib.request
from pathlib import Path

MODELS = {
    # Speaker embedding for diarization. 3D-Speaker ERes2Net base (16 kHz), ~40 MB.
    # Chosen over wespeaker_en_voxceleb_resnet34_LM after the 2026-09-22 feasibility test (ADR-007).
    "speaker/3dspeaker_speech_eres2net_base_sv_zh-cn_3dspeaker_16k.onnx":
        "https://github.com/k2-fsa/sherpa-onnx/releases/download/speaker-recongition-models/"
        "3dspeaker_speech_eres2net_base_sv_zh-cn_3dspeaker_16k.onnx",
}

root = Path(__file__).resolve().parent.parent / "models"
for rel, url in MODELS.items():
    dest = root / rel
    if dest.exists() and dest.stat().st_size > 1_000_000:
        print(f"ok      {rel}")
        continue
    dest.parent.mkdir(parents=True, exist_ok=True)
    print(f"getting {rel} ...", end=" ", flush=True)
    urllib.request.urlretrieve(url, dest)
    print(f"{dest.stat().st_size // 1_000_000} MB")
print("done", file=sys.stderr)
