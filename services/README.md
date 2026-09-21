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
