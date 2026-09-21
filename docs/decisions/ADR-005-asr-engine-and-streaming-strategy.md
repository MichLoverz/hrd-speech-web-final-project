# ADR-005: faster-whisper + Silero VAD, dan strategi streaming di sekeliling Whisper

Tanggal: 2026-09-22 · Status: diterima (parameter masih bisa berubah setelah pengukuran)

## 1. Engine: faster-whisper, bukan openai-whisper atau Vosk

| Opsi | Kelebihan | Kekurangan | Keputusan |
|---|---|---|---|
| `openai-whisper` (PyTorch) | referensi resmi | float32 di CPU lambat (RTF `small` sering > 1), butuh PyTorch ±200 MB+ | tidak |
| **`faster-whisper`** (CTranslate2) | model yang sama, kuantisasi int8, 2–4× lebih cepat di CPU, tanpa PyTorch | bukan streaming asli (sama seperti Whisper) | **ya** |
| Vosk (Kaldi) | streaming asli, sangat ringan | akurasi bahasa Indonesia jauh di bawah Whisper, tanpa tanda baca, model ID kecil | tidak (bisa jadi pembanding kalau waktu ada) |
| wav2vec2 Indonesia (HF) | encoder murni, cepat | butuh LM eksternal untuk teks yang rapi, tanpa tanda baca, campur kode lemah | tidak |

Alasan utama: akurasi bahasa Indonesia + campur kode (Whisper dilatih multibahasa dari 680 ribu jam) dan kecepatan CPU tanpa GPU (mesin: Ryzen 5 6600H, tanpa dGPU). `compute_type=int8`.

## 2. VAD: Silero v5 bawaan faster-whisper via onnxruntime

faster-whisper sudah membawa `silero_encoder_v5.onnx` + `silero_decoder_v5.onnx`. API batch-nya (`get_speech_timestamps`) mereset state tiap panggilan, jadi `app/asr/vad.py` memanggil sesi ONNX-nya langsung per frame 512 sampel dan menyimpan state LSTM + konteks 64 sampel antar-frame. Tidak ada dependensi tambahan (paket `silero-vad` terpisah menarik PyTorch). `webrtcvad` ditolak karena kualitasnya di derau kantor lebih buruk dan perlu compiler di Windows.

## 2b. Dua model: `base` untuk partial, `small` untuk final (ditambahkan setelah benchmark)

Benchmark 2026-09-22 (devlog) menunjukkan biaya per panggilan hampir konstan terhadap panjang audio — `base` ±0,6 s, `small` ±1,8 s (12 thread) — karena Whisper mem-pad setiap input ke 30 s. `small` tidak bisa mengejar jadwal partial 1,2 s. Keputusan: partial memakai `base` (cepat, boleh kasar karena akan diganti), final memakai `small` (teks yang disimpan dan diukur WER). Pada audio uji yang sama, median latency partial turun dari 2,5 s ke 1,0 s. `cpu_threads` diset ke semua core logis (default CT2 = 4).

## 3. Strategi streaming (lihat `app/asr/streamer.py`)

Whisper menerima jendela ≤30 s dan mengembalikan teks sekaligus. Real-time dibangun di sekelilingnya:

| Parameter | Nilai awal | Alasan |
|---|---|---|
| chunk masuk | 200 ms | cukup kecil untuk responsif, cukup besar untuk overhead WebSocket rendah |
| `partial_interval_ms` | 1200 | di bawah ini CPU terbuang untuk transkripsi ulang; di atas 1,5 s terasa lambat |
| `partial_window_s` | 12 | partial hanya mentranskripsi 12 s terakhir — membatasi waktu inference saat jawaban panjang |
| `vad_min_silence_ms` | 500 | sesuai spesifikasi; jeda alami antar kalimat |
| `vad_speech_pad_ms` | 200 | pre-roll agar suku kata pertama tidak terpotong VAD |
| `max_segment_s` | 25 | final dipaksa sebelum melewati jendela 30 s Whisper |
| beam partial / final | 1 / 2 | partial mengutamakan kecepatan; final sedikit lebih teliti |
| `condition_on_previous_text` | false | mencegah halusinasi "berlanjut" antar jendela |

**Aturan penting: partial tidak pernah mengantri.** Kalau inference masih berjalan saat jadwal partial tiba, permintaan dilewati (dihitung di `stats.partials_skipped`). Tanpa ini, CPU lambat membuat antrian menumpuk dan latency naik tanpa batas.

**Halusinasi saat hening** dimitigasi dengan: hanya mentranskripsi segmen yang VAD tandai berisi bicara; `no_speech_prob` dan `avg_logprob` disimpan per emit supaya bisa dianalisis (dan nanti dipakai sebagai ambang penolakan kalau datanya mendukung).

## 4. Konsekuensi yang harus diukur, bukan diasumsikan

- Teks partial bisa berubah antar emit (flicker). Ini sah menurut definisi dosen, tapi dicatat sebagai keterbatasan.
- Tiap partial mentranskripsi ulang audio yang sama → CPU terbuang. Trade-off diukur lewat `partials_skipped` dan RTF.
- Semua nilai di tabel di atas adalah **titik awal**. Nilai final ditetapkan setelah pengukuran di `docs/experiments/raw/latency/` dan perubahannya dicatat di devlog.
