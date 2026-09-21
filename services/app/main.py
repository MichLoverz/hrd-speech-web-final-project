"""
HireNote services: real-time ASR over WebSocket (+ file transcription over HTTP).

Run:  uvicorn app.main:app --host 127.0.0.1 --port 8765

WebSocket protocol  (/asr/stream)
---------------------------------
client -> server
  text  {"type":"start", "language":"id", "prompt":"<question text or empty>", "use_prompt":true}
        per-question mode (phase 3): one question, no diarization
  text  {"type":"start", "mode":"interview", "language":"id",
         "questions":[{"id":12,"text":"..."}, ...], "trigger_phrases":[...], "use_prompt":true}
        continuous-interview mode (phase 3b): diarization + question tracking; the prompt is
        rebuilt from the active question on every change
  bytes <8-byte float64 little-endian client_ms><int16 PCM 16 kHz mono ...>   (one chunk, ~200 ms)
  text  {"type":"set_question", "question_id":12}      manual override (interview mode)
  text  {"type":"stop"}
server -> client
  text  {"type":"ready", "final_model":..., "partial_model":..., "config":{...}, "mode":...}
  text  {"type":"partial"|"final", "segment_id":.., "seq":.., "text":.., "latency_ms":..,
         "chunk_received_at":.., "emitted_at":.., "real_time_factor":.., "model":..,
         "last_chunk_client_ms":.., "speaker":.., "speaker_similarity":.., "question_id":.., ...}
  text  {"type":"question_change", "question_id":.., "reason":"match|phrase|manual", "score":..}
  text  {"type":"speaker_relabel", "labels":{"<segment_id>":"interviewer|candidate", ...}}
        sent once after stop, when re-clustering all embeddings changed some online labels
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

from app.asr.diarizer import OnlineDiarizer
from app.asr.engine import WhisperEngine
from app.asr.questions import DEFAULT_TRIGGER_PHRASES, QuestionTracker
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


def _make_transcriber(on_event, *, language: str | None, prompt: str | None,
                      interview: dict | None = None, on_question_change=None) -> StreamingTranscriber:
    """interview = {"questions": [...], "trigger_phrases": [...]} switches on diarization + tracking."""
    cfg = AsrConfig(**{**CONFIG.to_dict(), "language": language})
    diarizer = tracker = None
    if interview is not None:
        diarizer = OnlineDiarizer()
        tracker = QuestionTracker(interview["questions"], interview.get("trigger_phrases") or DEFAULT_TRIGGER_PHRASES)
    return StreamingTranscriber(
        ENGINES["final"], cfg, on_event=on_event,
        partial_engine=ENGINES.get("partial"), initial_prompt=prompt,
        diarizer=diarizer, question_tracker=tracker, on_question_change=on_question_change,
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
                use_prompt = bool(cmd.get("use_prompt", True))
                mode = cmd.get("mode") or "question"
                prompt = (cmd.get("prompt") or "").strip() or None
                interview = None
                if mode == "interview":
                    questions = [{"id": int(q["id"]), "text": str(q["text"])} for q in cmd.get("questions") or []]
                    interview = {"questions": questions, "trigger_phrases": cmd.get("trigger_phrases")}
                    # No active question yet: bias the first segments with the first question.
                    prompt = questions[0]["text"] if (use_prompt and questions) else None
                if not use_prompt:
                    prompt = None

                def on_question_change(msg: dict) -> None:
                    # Re-bias Whisper with the question now being answered. Read by the worker
                    # threads on their next call; a plain attribute write is atomic in CPython.
                    if use_prompt and transcriber is not None:
                        qtext = next((q["text"] for q in interview["questions"] if q["id"] == msg["question_id"]), None)
                        transcriber.initial_prompt = qtext
                    loop.call_soon_threadsafe(outbox.put_nowait, msg)

                transcriber = _make_transcriber(on_event, language=language, prompt=prompt,
                                                interview=interview, on_question_change=on_question_change)
                await outbox.put({
                    "type": "ready",
                    "mode": mode,
                    "final_model": ENGINES["final"].name,
                    "partial_model": transcriber.partial_engine.name,
                    "speaker_model": transcriber.diarizer.model_name if transcriber.diarizer else None,
                    "sample_rate": SAMPLE_RATE,
                    "prompt_used": prompt,
                    "config": transcriber.config.to_dict(),
                })
            elif cmd.get("type") == "set_question":
                if transcriber is not None and transcriber.question_tracker is not None:
                    change = transcriber.question_tracker.set_manual(int(cmd.get("question_id")))
                    if change:
                        on_question_change({"type": "question_change", "question_id": change.question_id,
                                            "reason": "manual", "score": None, "segment_id": None, "text": None})
            elif cmd.get("type") == "stop":
                if transcriber is not None:
                    await loop.run_in_executor(None, transcriber.flush)
                    await loop.run_in_executor(None, transcriber.wait)
                    relabel = await loop.run_in_executor(None, transcriber.finalize_speakers)
                    if relabel:
                        await outbox.put({"type": "speaker_relabel", "labels": {str(k): v for k, v in relabel.items()}})
                    stats = {**transcriber.stats, "speaker_relabels": len(relabel)}
                    await outbox.put({"type": "done", "stats": stats})
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
