# Services (Python)

Satu aplikasi FastAPI, dua router:

- `/asr` — WebSocket transkripsi real-time (faster-whisper + Silero VAD). Fase 2.
- `/nlp/embed` — sentence embedding lokal (IndoBERT / multilingual MiniLM) untuk retrieval bukti dan verifikasi kutipan. Fase 4.

Python 3.12: `py -3.12 -m venv .venv`. Belum ada kode.
