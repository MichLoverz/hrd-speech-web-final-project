"""
Exercise the WebSocket protocol exactly like the browser will: stream a WAV
in 200 ms int16 chunks, each prefixed with an 8-byte client timestamp, and
print events with server latency and end-to-end latency.

    uvicorn app.main:app --port 8765          (in another terminal)
    python cli/ws_client_demo.py clip.wav --prompt "Ceritakan proyek terakhir Anda."
"""

from __future__ import annotations

import argparse
import asyncio
import json
import struct
import sys
import time
from pathlib import Path

import numpy as np
import websockets

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))
from app.config import SAMPLE_RATE      # noqa: E402
from file_demo import load_mono_16k     # noqa: E402


async def main() -> None:
    p = argparse.ArgumentParser()
    p.add_argument("wav", type=Path)
    p.add_argument("--url", default="ws://127.0.0.1:8765/asr/stream")
    p.add_argument("--language", default="id")
    p.add_argument("--prompt", default="")
    p.add_argument("--chunk-ms", type=int, default=200)
    a = p.parse_args()

    audio = load_mono_16k(a.wav)
    pcm = (np.clip(audio, -1, 1) * 32767).astype(np.int16)
    chunk = int(SAMPLE_RATE * a.chunk_ms / 1000)
    e2e: list[int] = []

    async with websockets.connect(a.url, max_size=None) as ws:
        await ws.send(json.dumps({"type": "start", "language": a.language, "prompt": a.prompt}))

        async def reader():
            async for raw in ws:
                msg = json.loads(raw)
                t = msg["type"]
                if t in ("partial", "final"):
                    e2e_ms = int(time.time() * 1000 - msg["last_chunk_client_ms"]) if msg.get("last_chunk_client_ms") else None
                    if e2e_ms is not None:
                        e2e.append(e2e_ms)
                    tag = "FINAL  " if t == "final" else "partial"
                    print(f"[{tag} seg{msg['segment_id']:>2}] server {msg['latency_ms']:>5} ms  e2e {e2e_ms!s:>5} ms  "
                          f"RTF {msg['real_time_factor']:<5} {msg['text']}")
                elif t == "done":
                    print("done", msg["stats"])
                    return
                else:
                    print(msg)

        reader_task = asyncio.create_task(reader())
        t0 = time.time()
        for i in range(0, len(pcm), chunk):
            due = t0 + (i / SAMPLE_RATE)
            delay = due - time.time()
            if delay > 0:
                await asyncio.sleep(delay)
            frame = struct.pack("<d", time.time() * 1000) + pcm[i:i + chunk].tobytes()
            await ws.send(frame)
        await ws.send(json.dumps({"type": "stop"}))
        await reader_task

    if e2e:
        arr = np.array(e2e)
        print(f"e2e latency: n={len(arr)} median={int(np.median(arr))} p95={int(np.percentile(arr, 95))} ms")


if __name__ == "__main__":
    asyncio.run(main())
