"""
HireNote services: real-time ASR over WebSocket (+ file transcription over HTTP).

Run:  uvicorn app.main:app --host 127.0.0.1 --port 8765

WebSocket protocol  (/asr/stream)
---------------------------------
client -> server
  text  {"type":"start", "language":"id", "prompt":"<question text or empty>", "use_prompt":true}
  bytes <8-byte float64 little-endian client_ms><int16 PCM 16 kHz mono ...>   (one chunk, ~200 ms)
  text  {"type":"stop"}
server -> client
  text  {"type":"ready", "final_model":..., "partial_model":..., "config":{...}}
  text  {"type":"partial"|"final", "segment_id":.., "seq":.., "text":.., "latency_ms":..,
         "chunk_received_at":.., "emitted_at":.., "real_time_factor":.., "model":..,
         "last_chunk_client_ms":.. , ...}
  text  {"type":"done", "stats":{...}}
  text  {"type":"error", "message":...}

The 8-byte client timestamp prefix lets the browser compute end-to-end
latency with its own clock (emitted event echoes last_chunk_client_ms), so
it also works when the browser is on another device with a different clock.

Models are loaded once at startup and shared; each connection gets its own
StreamingTranscriber (VAD state + buffers). Inference runs in the
transcriber's worker threads; events are handed to the asyncio loop with
call_soon_threadsafe.
"""

from __future__ import annotations

import asyncio
import json
import struct
import time
from contextlib import asynccontextmanager

import numpy as np
from fastapi import FastAPI, File, Form, UploadFile, WebSocket, WebSocketDisconnect
from fastapi.middleware.cors import CORSMiddleware

from app.asr.engine import WhisperEngine
from app.asr.streamer import StreamingTranscriber, TranscriptEvent
from app.config import DEFAULT, AsrConfig, SAMPLE_RATE

ENGINES: dict[str, WhisperEngine] = {}
CONFIG: AsrConfig = DEFAULT


@asynccontextmanager
async def lifespan(_app: FastAPI):
    t0 = time.time()
    ENGINES["final"] = WhisperEngine(CONFIG)
    if CONFIG.partial_model_size and CONFIG.partial_model_size != CONFIG.model_size:
        ENGINES["partial"] = WhisperEngine(CONFIG, model_size=CONFIG.partial_model_size)
    print(f"[asr] models loaded in {time.time() - t0:.1f}s: "
          f"final={ENGINES['final'].name} partial={ENGINES.get('partial', ENGINES['final']).name}")
    yield
    ENGINES.clear()


app = FastAPI(title="HireNote services", lifespan=lifespan)
app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],           # local development; the Laravel app runs on another port
    allow_methods=["*"],
    allow_headers=["*"],
)


@app.get("/health")
def health() -> dict:
    return {
        "ok": bool(ENGINES),
        "final_model": ENGINES["final"].name if ENGINES else None,
        "partial_model": ENGINES.get("partial", ENGINES.get("final")).name if ENGINES else None,
        "config": CONFIG.to_dict(),
    }


def _make_transcriber(on_event, *, language: str | None, prompt: str | None) -> StreamingTranscriber:
    cfg = AsrConfig(**{**CONFIG.to_dict(), "language": language})
    return StreamingTranscriber(
        ENGINES["final"], cfg, on_event=on_event,
        partial_engine=ENGINES.get("partial"), initial_prompt=prompt,
    )


@app.websocket("/asr/stream")
async def asr_stream(ws: WebSocket) -> None:
    await ws.accept()
    loop = asyncio.get_running_loop()
    outbox: asyncio.Queue[dict] = asyncio.Queue()

    def on_event(e: TranscriptEvent) -> None:
        loop.call_soon_threadsafe(outbox.put_nowait, e.to_message())

    transcriber: StreamingTranscriber | None = None

    async def sender() -> None:
        while True:
            msg = await outbox.get()
            if msg is None:
                return
            await ws.send_text(json.dumps(msg, ensure_ascii=False))

    send_task = asyncio.create_task(sender())
    try:
        while True:
            message = await ws.receive()
            if message.get("type") == "websocket.disconnect":
                break

            if "bytes" in message and message["bytes"] is not None:
                if transcriber is None:
                    await outbox.put({"type": "error", "message": "send a start message before audio"})
                    continue
                received_at = time.time()
                raw = message["bytes"]
                client_ms = struct.unpack("<d", raw[:8])[0] if len(raw) >= 8 else None
                pcm = np.frombuffer(raw[8:], dtype=np.int16).astype(np.float32) / 32768.0
                # VAD + scheduling is cheap, but keep the event loop free anyway.
                await loop.run_in_executor(None, transcriber.feed, pcm, received_at, client_ms)
                continue

            text = message.get("text")
            if not text:
                continue
            try:
                cmd = json.loads(text)
            except json.JSONDecodeError:
                await outbox.put({"type": "error", "message": "invalid JSON"})
                continue

            if cmd.get("type") == "start":
                language = cmd.get("language") or CONFIG.language
                prompt = (cmd.get("prompt") or "").strip() or None
                if not cmd.get("use_prompt", True):
                    prompt = None
                transcriber = _make_transcriber(on_event, language=language, prompt=prompt)
                await outbox.put({
                    "type": "ready",
                    "final_model": ENGINES["final"].name,
                    "partial_model": transcriber.partial_engine.name,
                    "sample_rate": SAMPLE_RATE,
                    "prompt_used": prompt,
                    "config": transcriber.config.to_dict(),
                })
            elif cmd.get("type") == "stop":
                if transcriber is not None:
                    await loop.run_in_executor(None, transcriber.flush)
                    await loop.run_in_executor(None, transcriber.wait)
                    await outbox.put({"type": "done", "stats": transcriber.stats})
                    transcriber.close()
                    transcriber = None
            else:
                await outbox.put({"type": "error", "message": f"unknown message type {cmd.get('type')!r}"})
    except WebSocketDisconnect:
        pass
    finally:
        if transcriber is not None:
            transcriber.close()
        await outbox.put(None)
        await send_task


@app.post("/asr/transcribe")
async def transcribe_file(
    audio: UploadFile = File(...),
    language: str = Form("id"),
    prompt: str = Form(""),
) -> dict:
    """
    Offline fallback: transcribe a whole WAV (16 kHz mono int16 or any
    soundfile-readable format) by replaying it through the same streaming
    pipeline without pacing. Returns the final segments. Latency fields are
    not meaningful here (no real-time arrival); real_time_factor is.
    """
    import io
    import soundfile as sf

    data, sr = sf.read(io.BytesIO(await audio.read()), dtype="float32", always_2d=True)
    data = data.mean(axis=1)
    if sr != SAMPLE_RATE:
        n_out = int(len(data) * SAMPLE_RATE / sr)
        data = np.interp(np.linspace(0, 1, n_out, endpoint=False),
                         np.linspace(0, 1, len(data), endpoint=False), data).astype(np.float32)

    events: list[dict] = []
    transcriber = _make_transcriber(lambda e: events.append(e.to_message()),
                                    language=language or None, prompt=prompt.strip() or None)

    def run() -> None:
        chunk = int(SAMPLE_RATE * 0.2)
        for i in range(0, len(data), chunk):
            transcriber.feed(data[i:i + chunk])
        transcriber.flush()
        transcriber.wait()
        transcriber.close()

    await asyncio.get_running_loop().run_in_executor(None, run)
    finals = sorted((e for e in events if e["type"] == "final"), key=lambda e: e["start_ms"])
    return {
        "model": ENGINES["final"].name,
        "prompt_used": prompt.strip() or None,
        "duration_ms": int(len(data) / SAMPLE_RATE * 1000),
        "text": " ".join(e["text"] for e in finals if e["text"]),
        "segments": finals,
        "stats": transcriber.stats,
    }
