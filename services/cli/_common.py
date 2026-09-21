"""Shared bits for the CLI demos: argument parsing, terminal rendering, run wrap-up."""

from __future__ import annotations

import argparse
import sys
from pathlib import Path

# Make `import app...` work when running `python cli/mic_demo.py` from services/.
sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from app.asr.engine import WhisperEngine          # noqa: E402
from app.asr.metrics import RunRecorder           # noqa: E402
from app.asr.streamer import StreamingTranscriber, TranscriptEvent  # noqa: E402
from app.config import AsrConfig, SERVICES_DIR    # noqa: E402

DEFAULT_OUT = SERVICES_DIR.parent / "docs" / "experiments" / "raw" / "latency"


def add_common_args(p: argparse.ArgumentParser) -> None:
    p.add_argument("--model", default="small", help="model for FINAL transcripts: tiny | base | small | medium | large-v3")
    p.add_argument("--partial-model", default="base", help="model for PARTIAL transcripts, or 'same' to reuse --model")
    p.add_argument("--threads", type=int, default=0, help="CPU threads for CTranslate2 (0 = all logical cores)")
    p.add_argument("--compute", default="int8", help="int8 | int8_float16 | float32")
    p.add_argument("--language", default="id", help="Whisper language code, or 'auto'")
    p.add_argument("--partial-interval", type=int, default=1200, help="ms between partial transcripts")
    p.add_argument("--min-silence", type=int, default=500, help="ms of silence that ends a segment")
    p.add_argument("--vad-threshold", type=float, default=0.5)
    p.add_argument("--chunk-ms", type=int, default=200, help="audio chunk size fed to the streamer")
    p.add_argument("--label", default="run", help="name used for the CSV/JSON output files")
    p.add_argument("--out", default=str(DEFAULT_OUT), help="folder for raw latency logs")
    p.add_argument("--no-save", action="store_true")


def config_from_args(a: argparse.Namespace) -> AsrConfig:
    import os
    return AsrConfig(
        model_size=a.model,
        partial_model_size=None if a.partial_model in ("same", "none", "") else a.partial_model,
        cpu_threads=a.threads or (os.cpu_count() or 4),
        compute_type=a.compute,
        language=None if a.language == "auto" else a.language,
        partial_interval_ms=a.partial_interval,
        vad_min_silence_ms=a.min_silence,
        vad_threshold=a.vad_threshold,
    )


class TerminalRenderer:
    """Partials overwrite one line in pencil-grey; finals are printed for good."""

    GREY = "\033[90m"
    RESET = "\033[0m"
    BOLD = "\033[1m"

    def __init__(self, recorder: RunRecorder):
        self.recorder = recorder
        self._partial_len = 0

    def __call__(self, e: TranscriptEvent) -> None:
        self.recorder.add(e)
        if e.is_final:
            self._clear_partial()
            print(f"{self.BOLD}{e.text}{self.RESET}  "
                  f"{self.GREY}[final  latency {e.latency_ms} ms  RTF {e.real_time_factor}  "
                  f"{e.audio_duration_ms / 1000:.1f}s]{self.RESET}")
        else:
            line = f"{self.GREY}{e.text}  [partial  latency {e.latency_ms} ms  RTF {e.real_time_factor}]{self.RESET}"
            self._clear_partial()
            sys.stdout.write(line)
            sys.stdout.flush()
            self._partial_len = len(line)

    def _clear_partial(self) -> None:
        if self._partial_len:
            sys.stdout.write("\r" + " " * min(self._partial_len, 200) + "\r")
            self._partial_len = 0


def load_engines(config: AsrConfig) -> tuple[WhisperEngine, WhisperEngine | None]:
    """Returns (final_engine, partial_engine_or_None)."""
    print(f"Memuat model final {config.model_size}/{config.compute_type} ({config.cpu_threads} thread) ...", end=" ", flush=True)
    engine = WhisperEngine(config)
    print(f"selesai ({engine.load_seconds}s)")
    partial = None
    if config.partial_model_size and config.partial_model_size != config.model_size:
        print(f"Memuat model partial {config.partial_model_size} ...", end=" ", flush=True)
        partial = WhisperEngine(config, model_size=config.partial_model_size)
        print(f"selesai ({partial.load_seconds}s)")
    return engine, partial


def finish(recorder: RunRecorder, streamer: StreamingTranscriber, a: argparse.Namespace, extra: dict | None = None) -> None:
    extra = {**(extra or {}), **streamer.stats}
    recorder.print_summary(extra)
    if not a.no_save:
        csv_path, json_path = recorder.save(Path(a.out), extra={"stats": streamer.stats})
        print(f"\nLog mentah: {csv_path}\n            {json_path}")
