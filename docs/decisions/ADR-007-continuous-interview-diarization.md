# ADR-007: Wawancara utuh dengan pembedaan suara dan pelacakan pertanyaan otomatis

Tanggal: 2026-09-22 · Status: diterima (menggantikan sebagian ADR-006 §3)

## Konteks

Gambaran awal mahasiswa: satu rekaman wawancara utuh (±30 menit), sistem membedakan suara pewawancara dan kandidat, lalu jawaban dipecah per pertanyaan. Mode rekam-per-pertanyaan (ADR-006) dibangun dulu karena gambaran ini baru tersampaikan setelah Fase 3 — bukan kesalahan, dan tidak ada yang terbuang: VAD, streamer, dua jalur inference, WebSocket, AudioWorklet, dan penyimpanan tetap dipakai.

## Keputusan

Mode utama = **wawancara kontinu**; mode per-pertanyaan tetap tersedia sebagai cadangan.

### 1. Diarization: speaker embedding + dua pusat suara (online) + klasterisasi ulang (akhir)

- Tiap segmen VAD → embedding suara (sherpa-onnx, model **3D-Speaker ERes2Net base**, ONNX, ±70 ms/segmen, tanpa PyTorch).
- Online: pusat 0 = pewawancara (suara substansial pertama; pewawancara membuka sesi), pusat 1 = suara pertama yang jelas berbeda (similarity < 0,55). Segmen dilabeli ke pusat terdekat; pusat diperbarui dengan rata-rata berjalan.
- **Hanya segmen ≥ 2 s yang boleh membuka/memperbarui pusat.** Uji browser pertama gagal total (33%) karena "Selamat pagi." 1,2 detik yang sudah melewati noise-suppression Chrome menjadi pusat pewawancara yang buruk.
- **Di akhir wawancara** semua embedding diklaster ulang (2-means); klaster berisi segmen substansial pertama = pewawancara. Label yang berubah dikirim ke browser (`speaker_relabel`) dan diunggah ulang. Online-greedy tidak bisa membatalkan kesalahan awal; klasterisasi global bisa.
- Partial diberi label lewat `peek()` (tanpa memperbarui pusat) supaya audio yang sama tidak dihitung dua kali.

Pembanding yang ditolak: WeSpeaker voxceleb ResNet34-LM (semua similarity 0,71–0,94 → tidak bisa memisahkan dua suara berapa pun ambangnya); pyannote (butuh PyTorch, offline-batch).

### 2. Pelacakan pertanyaan, tiga lapis (`app/asr/questions.py`)

Hanya segmen final berlabel **pewawancara** yang bisa memindahkan pertanyaan:
1. **Cocok teks** — kemiripan (½ Jaccard token + ½ rasio difflib) antara ucapan pewawancara dan pertanyaan yang belum ditanyakan; ≥ 0,45 → aktif. Pewawancara membaca pertanyaan dari layar, jadi ini lapis paling kuat.
2. **Frasa pemicu** — "lanjut ke pertanyaan berikutnya", "next question", dst. (dapat diatur di `ASR_TRIGGER_PHRASES`), dicocokkan fuzzy ≥ 0,8 → pertanyaan berikutnya yang belum ditanyakan.
3. **Manual** — klik pertanyaan / tombol "Pertanyaan berikutnya" (`set_question`).

`initial_prompt` Whisper mengikuti pertanyaan aktif. Setiap event membawa `question_id`; jawaban per pertanyaan **diturunkan** dari segmen kandidat berlabel pertanyaan itu (`AnswerBuilder`), bukan direkam terpisah. Segmen bisa dipindah/dilabel ulang di halaman sesi, lalu jawaban dibangun ulang.

### 3. Penyimpanan bertahap

Transkrip (upsert per `client_seq`) tiap 2 s dan PCM mentah tiap 10 s dikirim ke Laravel selama wawancara; `finish` membungkus PCM jadi WAV dan menyusun jawaban. Crash browser kehilangan paling banyak beberapa detik.

## Bukti (2026-09-22, sintetis: pewawancara = TTS David, kandidat = rekaman mahasiswa)

| Uji | Hasil |
|---|---|
| Offline, dua suara laki-laki, ERes2Net | online 20/20, setelah finalize 21/21 (ambang 0,40–0,55 sama; 0,70 → 20/21) |
| Offline, laki-laki vs perempuan (TTS) | 13/13 |
| Offline, WeSpeaker | 7/21 (thr 0,55), 1/21 (thr 0,80) |
| Browser headless, aturan lama | 7/21 — pusat pertama dari segmen 1,2 s |
| Browser headless, aturan ≥2 s + finalize | **20/20** |
| Pelacakan pertanyaan (klien WS) | 5/5 pertanyaan terdeteksi; satu lewat lapis frasa setelah lapis cocok gagal karena Whisper *menerjemahkan* pertanyaan Inggris (artefak `language=auto`) |

**Belum diuji dengan dua manusia** — wajib sebelum angka masuk laporan.

## Konsekuensi & risiko yang diakui

- Bicara tumpang-tindih → satu label. Dua suara mirip di ruangan berisik → bisa tertukar; itu sebabnya ada koreksi pasca-wawancara dan angka similarity disimpan per segmen.
- Pertanyaan yang salah ditranskripsi saat dibaca → lapis 1 gagal; lapis 2/3 dan koreksi menutup.
- Pewawancara ikut tertranskripsi (dibutuhkan untuk pelacakan); teksnya disimpan berlabel dan tidak masuk ke penilaian.
- Eksperimen baru untuk laporan: akurasi label pembicara vs label manual; precision/recall deteksi pergantian pertanyaan; pengaruh noise-suppression browser terhadap keduanya.
