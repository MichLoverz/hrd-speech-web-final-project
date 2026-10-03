# Catatan akun & alamat (pengembangan lokal)

Semua akun di bawah ini hanya ada di database lokal hasil `php artisan migrate:fresh --seed`. Jangan dipakai di server mana pun.

## Akun aplikasi web

| Peran | Email | Kata sandi | Bisa apa |
|---|---|---|---|
| Admin | `admin@hirenote.test` | `password` | semua data, menu **Pengguna** (ubah peran) |
| HRD | `hrd@hirenote.test` | `password` | buat/ubah lowongan miliknya, kandidat, sesi, rekam, nilai |
| Pewawancara | `interviewer@hirenote.test` | `password` | lihat, ikut panel, nilai sesi yang diikuti |

Nama tampilan: Admin HireNote, Dewi Anggraini (HRD), Bagus Prasetyo (pewawancara).

## Alamat saat dijalankan lokal

| Apa | Alamat |
|---|---|
| Aplikasi web | <http://127.0.0.1:8000> (`cd web && php artisan serve`) |
| ASR service — cek hidup | <http://127.0.0.1:8765/health> (`cd services && .venv\Scripts\activate && uvicorn app.main:app --host 127.0.0.1 --port 8765`) |
| ASR WebSocket | `ws://127.0.0.1:8765/asr/stream` (diatur di `web/.env`, `ASR_WS_URL`) |
| Database | MariaDB XAMPP, host `127.0.0.1`, db `hirenote`, user `root`, sandi kosong |
| phpMyAdmin (kalau Apache XAMPP dinyalakan) | <http://localhost/phpmyadmin> |

## Kunci API (isi sendiri, jangan commit)

| Layanan | Variabel di `web/.env` | Catatan |
|---|---|---|
| Gemini API, tier gratis (Fase 4, **hanya untuk data uji**) | `LLM_API_KEY` dengan `LLM_PROVIDER=gemini` | buat di <https://aistudio.google.com> → *Get API key*. Cek dengan `php artisan llm:ping`. Jangan tempel key di chat |
| OpenRouter (eksperimen dan data peserta, berbayar) | `LLM_API_KEY` dengan `LLM_PROVIDER=openrouter`, `LLM_BASE_URL=https://openrouter.ai/api/v1`, `LLM_TEST_DATA_ONLY=false` | belum dibuat; top-up ±$10 menjelang eksperimen (ADR-009). `.env` sudah di-ignore git |

## Model lokal yang harus ada (`services/models/`, git-ignored)

| Model | Cara dapat |
|---|---|
| faster-whisper `base` dan `small` | otomatis saat service pertama jalan |
| Speaker embedding `3dspeaker_speech_eres2net_base_sv_zh-cn_3dspeaker_16k.onnx` | `python scripts/download_models.py` (di `services/`, venv aktif) |

## Data contoh (seeder)

- Lowongan: Backend Developer (Rp 7–11 jt), Customer Support Specialist (Rp 4,5–6 jt).
- Kandidat berstatus "Tahap wawancara" dengan sesi draf: Rina Sulistyawati (Backend), Putri Maharani (CS).
- Reset ke kondisi awal: `cd web && php artisan migrate:fresh --seed` (menghapus semua rekaman & transkrip di database; berkas WAV di `web/storage/app/private/recordings/` tidak ikut terhapus).
