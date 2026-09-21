# ADR-006: AudioWorklet, protokol WebSocket, rekam per pertanyaan, dan prompt konteks

Tanggal: 2026-09-22 · Status: diterima

## 1. AudioWorklet, bukan MediaRecorder

| | MediaRecorder | AudioWorklet |
|---|---|---|
| Keluaran | berkas terkompresi (webm/opus) dalam potongan | sampel PCM mentah tiap 128 frame |
| Cocok untuk | menyimpan rekaman | memproses audio selagi masuk |
| Untuk ASR streaming | harus di-decode dulu di server, potongan webm tidak selalu bisa berdiri sendiri | langsung diubah ke Int16 16 kHz dan dikirim |

Keputusan: AudioWorklet (`web/public/audio/pcm-processor.js`). `AudioContext` diminta di 16 kHz; kalau browser menolak, worklet melakukan resample linier sendiri. Chunk 200 ms (3.200 sampel Int16 = 6,4 KB) dikirim lewat WebSocket. Audio yang sama disimpan di memori dan diubah menjadi WAV bukti saat berhenti — **penyimpanan tidak pernah menghalangi jalur transkripsi** (unggah WAV adalah request terpisah setelah transkrip tersimpan).

## 2. Protokol WebSocket (`services/app/main.py`)

- `start` (JSON: bahasa, prompt) → `ready` (model & konfigurasi yang dipakai).
- Frame biner: **8 byte float64 timestamp klien** + PCM. Service menyimpan timestamp chunk terakhir tiap segmen dan mengembalikannya sebagai `last_chunk_client_ms` di setiap event, sehingga browser menghitung **latency end-to-end dengan jamnya sendiri** — tetap benar meski browser di perangkat lain (HP) yang jamnya berbeda dari server.
- `partial` / `final` membawa `segment_id`; final menggantikan partial dengan `segment_id` yang sama. Perlu karena dua jalur inference (ADR-005 §2b + devlog 2026-09-22) bisa mengirim final segmen N *setelah* partial segmen N+1.
- `stop` → service memproses segmen terakhir → `done` (statistik).

Dua angka latency disimpan per emit di `transcript_segments`: `latency_ms` (jam service: chunk diterima → teks dikirim) dan `e2e_latency_ms` (jam browser: chunk dikirim → teks tampil). Selisihnya = biaya transport; di satu mesin ±2 ms.

## 3. Rekam per pertanyaan, bukan satu sesi panjang

- Tiap jawaban terikat ke `question_id` → ke `criterion_id`, jadi pengelompokan transkrip per kriteria untuk kartu skor terjadi otomatis, tanpa diarization atau segmentasi topik.
- Segmen ≤25 s tetap berlaku di dalam jawaban (Whisper 30 s), tapi batas jawaban memberi titik potong alami dan tempat untuk "Rekam ulang".
- Rekaman gagal hanya merusak satu jawaban, bukan seluruh sesi.
- Kelemahan: pewawancara harus menekan mulai/selesai tiap pertanyaan — beban interaksi yang harus diuji di user testing.

## 4. `initial_prompt` = teks pertanyaan (contextual biasing)

Whisper menerima `initial_prompt` sebagai "teks sebelumnya", sehingga kosakata di dalamnya lebih mungkin muncul di keluaran. HireNote punya sumber prompt alami: pertanyaan yang sedang ditanyakan sudah memuat istilah domain ("code review", "Backend Developer", "query"). Diaktifkan lewat `ASR_USE_PROMPT` dan dicatat per jawaban di `answers.asr_prompt`, supaya kondisi *dengan vs tanpa prompt* bisa dibandingkan pada rekaman yang sama lewat `/asr/transcribe`.

**Risiko yang sudah terlihat** (uji end-to-end 2026-09-22 dengan audio yang tidak cocok bahasanya): partial berbunyi "Bagaimana terakhir yang terakhir di dalamnya?" — kata-kata dari prompt bocor ke output saat audio tidak jelas. Ini hipotesis eksperimen: prompt membantu istilah teknis tapi bisa menambah halusinasi di audio buruk. Diputuskan setelah diukur.

## 5. Kegagalan

- Service mati saat mulai → UI memberi tahu, audio tetap direkam ke WAV, tombol **Transkripsi ulang** memakai `/asr/transcribe` (mode `file`, tanpa angka latency).
- WebSocket putus di tengah → sama; rekaman lokal tetap utuh.
- Transkrip mentah ASR (`transcript_raw`) tidak pernah ditimpa oleh koreksi HRD (`transcript_edited`), supaya WER tetap bisa dihitung terhadap keluaran sistem yang sebenarnya.
