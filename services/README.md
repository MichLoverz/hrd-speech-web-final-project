# Services (Python)

Proses Python terpisah dari Laravel (lihat ADR-002). Fase 2: ASR real-time yang bisa diuji dari terminal. Fase 3 menambah WebSocket, Fase 4 menambah `/nlp/embed`.

## Instalasi (sekali)

```powershell
cd services
py -3.12 -m venv .venv
.venv\Scripts\activate
pip install -r requirements.txt
```

Model faster-whisper diunduh otomatis ke `services/models/` saat pertama dipakai (`small` ≈ 480 MB, `medium` ≈ 1,5 GB). Folder ini di-ignore git.

## Menjalankan demo (Fase 2)

```powershell
.venv\Scripts\activate
python cli/mic_demo.py                          # bicara; Ctrl+C untuk berhenti
python cli/mic_demo.py --list-devices           # kalau mikrofon salah pilih
python cli/mic_demo.py --model medium --label medium-quiet
python cli/file_demo.py rekaman.wav --label baseline   # dari berkas, dipacu real-time
python cli/file_demo.py rekaman.wav --fast             # tanpa pacing (hanya untuk cek teks)
```

Tiap run mencetak ringkasan (latency mean/median/p95, RTF) dan menyimpan log mentah per emit ke `docs/experiments/raw/latency/<stamp>_<label>.csv` + `.json` (konfigurasi, spesifikasi mesin, ringkasan). Pakai `--label` yang bermakna, misalnya `baseline-quiet`, `e1-office-noise`, `e5-medium`.

## Struktur

```
app/
  config.py           semua parameter (model, beam, interval partial, ambang VAD)
  asr/
    engine.py         pembungkus faster-whisper: audio → teks + waktu inference
    vad.py            Silero VAD per frame 32 ms (ONNX bawaan faster-whisper, tanpa PyTorch)
    streamer.py       StreamingTranscriber: ring buffer, partial/final, instrumentasi latency
    metrics.py        agregasi latency/RTF, tulis CSV/JSON
  nlp/
    embedder.py       embedding kalimat (fastembed/ONNX, tanpa PyTorch) untuk kartu skor; dimuat saat dipakai pertama kali
cli/
  mic_demo.py         mikrofon → terminal
  file_demo.py        WAV → terminal, dipacu seperti live
```

## Cara kerja singkat

1. Audio 16 kHz mono masuk per chunk 200 ms.
2. Tiap chunk dipotong jadi frame 512 sampel → Silero VAD → probabilitas bicara.
3. Saat bicara terdeteksi, chunk ditumpuk di buffer segmen (dengan 200 ms pre-roll supaya suku kata pertama tidak terpotong).
4. Tiap 1,2 s bicara: transkripsi ≤12 s terakhir dari buffer → **partial** (greedy, beam 1). Kalau inference sebelumnya masih jalan, partial dilewati — tidak pernah mengantri.
5. Jeda ≥500 ms (atau segmen ≥25 s): transkripsi seluruh segmen → **final** (beam 2), buffer dikosongkan.
6. Tiap emit mencatat `chunk_received_at`, `inference_started_at`, `inference_ended_at`, `emitted_at`, `latency_ms`, `real_time_factor`.

## Embedding untuk kartu skor (Fase 4, ADR-008)

`POST /nlp/embed` dengan body `{"texts": [...], "kind": "query"|"passage", "model": null}` mengembalikan vektor ternormalisasi (cosine = dot product). Retrieval bukti dan verifikasi kutipan berjalan di Laravel (`web/app/Services/Nlp/`); service hanya menghitung vektor.

- Model bawaan: `sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2` (versi ONNX terkuantisasi dari Qdrant, 0,22 GB), diunduh ke `services/models/embed/` saat permintaan pertama (±16 s). Ganti lewat env `HIRENOTE_EMBED_MODEL` di service atau `NLP_EMBED_MODEL` di Laravel; hanya model di `ALLOWED_MODELS` yang diterima.
- **Tidak dimuat saat startup**, jadi startup dan streaming ASR sama persis seperti sebelum Fase 4.
- Peringatan symlink dari `huggingface_hub` di Windows tidak berbahaya; cache tetap jalan, hanya memakai sedikit lebih banyak ruang disk.

```powershell
curl.exe -s -X POST http://127.0.0.1:8765/nlp/embed -H "Content-Type: application/json" -d '{\"texts\":[\"halo\"]}'
```
