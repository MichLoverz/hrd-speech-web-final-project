# ADR-002: ASR dijalankan sebagai service Python terpisah, bukan di dalam Laravel

Tanggal: 2026-09-21 · Status: diterima

## Konteks

Syarat mata kuliah Speech Recognition: transkripsi real-time streaming dengan model lokal, partial transcript muncul selagi orang bicara. Aplikasi utama adalah Laravel (PHP).

## Opsi yang dipertimbangkan

1. Panggil model ASR dari PHP lewat `exec()`/FFI di dalam request HTTP.
2. Jalankan ASR sebagai proses Python terpisah (FastAPI + WebSocket) dan biarkan browser terhubung langsung ke sana.
3. Laravel sebagai perantara WebSocket (Reverb) yang meneruskan audio ke Python.

## Keputusan

Opsi 2. Browser mengirim audio PCM lewat WebSocket langsung ke ASR service; ASR service mengembalikan partial/final transcript ke browser; browser (atau ASR service) mengirim hasil final + metrik ke Laravel lewat HTTP.

## Alasan

- Inference PyTorch/CTranslate2 tidak bisa berjalan di dalam proses PHP; opsi 1 juga akan menabrak timeout request dan tidak bisa streaming.
- Opsi 3 menambah satu lompatan jaringan dan satu komponen (Reverb) tanpa menambah nilai pada penilaian; latency yang diukur juga jadi tercampur overhead relay.
- Pemisahan membuat model ASR bisa diganti (small/medium, atau model lain) tanpa menyentuh Laravel — ini yang dibutuhkan untuk eksperimen E5.

## Lingkungan eksekusi

Kedua proses berjalan **native di Windows 11**, tanpa WSL. Alasan: mesin pengembangan tidak punya GPU NVIDIA (Ryzen 5 6600H, iGPU), sehingga tidak ada keuntungan CUDA dari Linux; `faster-whisper`, `onnxruntime` (Silero VAD), FastAPI, dan FFmpeg semuanya punya build Windows resmi. Menghilangkan WSL menghindari penerusan port antar-OS, selisih jam saat mengukur latency, dan panduan instalasi ganda.

## Konsekuensi

- Perlu dua proses hidup saat demo: `php artisan serve` dan `uvicorn`.
- Kegagalan ASR service harus ditangani di UI (status jelas, audio tetap direkam, tombol transkripsi ulang).
- Latency harus diukur dua kali: di sisi ASR service (server-side) dan di browser (end-to-end), karena browser dan service punya jam yang sama di mesin yang sama tetapi tetap dipisah agar analisis bisa membedakan waktu inference dari waktu transport.
