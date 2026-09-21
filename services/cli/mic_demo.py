"""
Phase 2 demo: speak into the microphone, watch partial transcripts appear
while you talk, finals when you pause, with latency printed per emit.

    python cli/mic_demo.py                    # small / int8 / Indonesian
    python cli/mic_demo.py --model medium     # compare
    python cli/mic_demo.py --label baseline-quiet-room

Press Ctrl+C to stop; a summary and raw CSV/JSON are written to
docs/experiments/raw/latency/.

Threading: the sounddevice callback only pushes audio into a queue. A
worker thread pulls chunks and runs VAD + inference. That way a slow
inference never causes the audio driver to drop samples.
"""

from __future__ import annotations

import argparse
import queue
import threading
import time

import numpy as np
import sounddevice as sd

from _common import TerminalRenderer, add_common_args, config_from_args, finish, load_engines
from app.asr.metrics import RunRecorder
from app.asr.streamer import StreamingTranscriber
from app.config import SAMPLE_RATE


def main() -> None:
    p = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    add_common_args(p)
    p.add_argument("--device", default=None, help="sounddevice input device index or name (see --list-devices)")
    p.add_argument("--list-devices", action="store_true")
    a = p.parse_args()

    if a.list_devices:
        print(sd.query_devices())
        return

    config = config_from_args(a)
    engine, partial_engine = load_engines(config)
    recorder = RunRecorder(config, label=a.label)
    streamer = StreamingTranscriber(engine, config, on_event=TerminalRenderer(recorder), partial_engine=partial_engine)

    audio_q: "queue.Queue[tuple[np.ndarray, float] | None]" = queue.Queue()
    dropped = {"chunks": 0}
    backlog = {"max": 0}

    def on_audio(indata, frames, time_info, status):
        if status and status.input_overflow:
            dropped["chunks"] += 1
        # Stamp arrival time here, in the audio thread, before any queueing.
        audio_q.put((indata[:, 0].copy(), time.time()))
        backlog["max"] = max(backlog["max"], audio_q.qsize())

    def worker():
        while True:
            item = audio_q.get()
            if item is None:
                break
            chunk, received_at = item
            streamer.feed(chunk, received_at=received_at)

    t = threading.Thread(target=worker, daemon=True)
    t.start()

    blocksize = int(SAMPLE_RATE * a.chunk_ms / 1000)
    device = int(a.device) if a.device and a.device.isdigit() else a.device
    print(f"Mendengarkan (chunk {a.chunk_ms} ms, partial tiap {config.partial_interval_ms} ms, "
          f"jeda {config.vad_min_silence_ms} ms). Ctrl+C untuk berhenti.\n")

    started = time.time()
    try:
        with sd.InputStream(samplerate=SAMPLE_RATE, channels=1, dtype="float32",
                            blocksize=blocksize, device=device, callback=on_audio):
            while True:
                time.sleep(0.25)
    except KeyboardInterrupt:
        pass
    finally:
        audio_q.put(None)
        t.join(timeout=30)
        streamer.flush()
        wall = round(time.time() - started, 1)
        finish(recorder, streamer, a, extra={"wall_seconds": wall, "input_overflows": dropped["chunks"],
                                             "max_queue_backlog_chunks": backlog["max"]})


if __name__ == "__main__":
    main()
