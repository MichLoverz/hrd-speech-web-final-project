"""
Collects TranscriptEvents from a run and turns them into the numbers the
Technical Report needs: latency mean/median/p95 and real-time factor, split
by partial vs final. Also writes the raw per-emit CSV so the aggregates can
be recomputed later.
"""

from __future__ import annotations

import csv
import json
import platform
import statistics
from datetime import datetime
from pathlib import Path

import numpy as np

from app.asr.streamer import TranscriptEvent
from app.config import AsrConfig


def machine_info() -> dict:
    info = {
        "platform": platform.platform(),
        "machine": platform.machine(),
        "processor": platform.processor(),
        "python": platform.python_version(),
    }
    try:
        import os
        info["cpu_count"] = os.cpu_count()
    except Exception:
        pass
    try:
        import ctranslate2, faster_whisper, onnxruntime
        info["faster_whisper"] = faster_whisper.__version__
        info["ctranslate2"] = ctranslate2.__version__
        info["onnxruntime"] = onnxruntime.__version__
    except Exception:
        pass
    return info


class RunRecorder:
    def __init__(self, config: AsrConfig, label: str = "run"):
        self.config = config
        self.label = label
        self.events: list[TranscriptEvent] = []
        self.started_at = datetime.now()

    def add(self, event: TranscriptEvent) -> None:
        self.events.append(event)

    # ------------------------------------------------------------- summaries

    @staticmethod
    def _describe(values: list[float]) -> dict:
        if not values:
            return {"n": 0}
        arr = np.asarray(values, dtype=float)
        return {
            "n": len(values),
            "mean": round(float(arr.mean()), 1),
            "median": round(float(np.median(arr)), 1),
            "p95": round(float(np.percentile(arr, 95)), 1),
            "min": round(float(arr.min()), 1),
            "max": round(float(arr.max()), 1),
        }

    def summary(self) -> dict:
        partials = [e for e in self.events if not e.is_final]
        finals = [e for e in self.events if e.is_final]
        return {
            "label": self.label,
            "events": len(self.events),
            "partial_latency_ms": self._describe([e.latency_ms for e in partials]),
            "final_latency_ms": self._describe([e.latency_ms for e in finals]),
            "partial_rtf": self._describe([e.real_time_factor for e in partials]),
            "final_rtf": self._describe([e.real_time_factor for e in finals]),
            "audio_seconds_transcribed_final": round(sum(e.audio_duration_ms for e in finals) / 1000, 1),
        }

    def print_summary(self, extra: dict | None = None) -> None:
        s = self.summary()

        def line(name: str, d: dict) -> str:
            if d.get("n", 0) == 0:
                return f"  {name:<22} (tidak ada)"
            return (f"  {name:<22} n={d['n']:<4} mean={d['mean']:<8} median={d['median']:<8} "
                    f"p95={d['p95']:<8} min={d['min']:<8} max={d['max']}")

        print("\n=== Ringkasan ===")
        print(f"  model final / partial  {self.config.model_size} / {self.config.partial_model_size or self.config.model_size}"
              f"  ({self.config.compute_type}, {self.config.cpu_threads} thread)")
        print(line("partial latency (ms)", s["partial_latency_ms"]))
        print(line("final latency (ms)", s["final_latency_ms"]))
        print(line("partial RTF", s["partial_rtf"]))
        print(line("final RTF", s["final_rtf"]))
        print(f"  audio final (detik)    {s['audio_seconds_transcribed_final']}")
        for k, v in (extra or {}).items():
            print(f"  {k:<22} {v}")

    # ---------------------------------------------------------------- output

    def save(self, out_dir: Path, extra: dict | None = None) -> tuple[Path, Path]:
        """Writes <stamp>_<label>.csv (per emit) and .json (config, machine, summary)."""
        out_dir.mkdir(parents=True, exist_ok=True)
        stamp = self.started_at.strftime("%Y%m%d-%H%M%S")
        csv_path = out_dir / f"{stamp}_{self.label}.csv"
        json_path = out_dir / f"{stamp}_{self.label}.json"

        rows = [e.to_row() for e in self.events]
        with csv_path.open("w", newline="", encoding="utf-8") as f:
            writer = csv.DictWriter(f, fieldnames=list(rows[0].keys()) if rows else ["seq"])
            writer.writeheader()
            writer.writerows(rows)

        payload = {
            "label": self.label,
            "started_at": self.started_at.isoformat(timespec="seconds"),
            "config": self.config.to_dict(),
            "machine": machine_info(),
            "summary": self.summary(),
            **(extra or {}),
        }
        json_path.write_text(json.dumps(payload, indent=2, ensure_ascii=False), encoding="utf-8")
        return csv_path, json_path
