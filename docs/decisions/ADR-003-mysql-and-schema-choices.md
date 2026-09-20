# ADR-003: MySQL/MariaDB sebagai database, dan beberapa pilihan skema

Tanggal: 2026-09-21 · Status: diterima

## Database

**Keputusan:** MariaDB 10.4 (bawaan XAMPP), diakses Laravel lewat driver `mysql`.

**Konteks:** Rencana awal PostgreSQL. Saat memeriksa mesin, PostgreSQL belum terpasang sedangkan MariaDB sudah aktif dengan `pdo_mysql` di PHP.

**Alasan:** Tidak ada fitur di skema HireNote yang butuh Postgres (tidak ada query JSONB, array, atau full-text khusus; `raw_json` cukup kolom `json`). Satu komponen lebih sedikit di panduan instalasi. Laravel 12 mendukung MariaDB 10.3+.

**Konsekuensi:** Kolom `json` di MariaDB 10.4 disimpan sebagai `longtext` dengan validasi JSON — cukup karena kita hanya simpan dan baca utuh, tidak query ke dalamnya.

## Pilihan skema yang perlu dijelaskan

| Pilihan | Alasan |
|---|---|
| `questions.position`, bukan `order` | `ORDER` reserved word SQL; menghindari quoting di setiap query. |
| `interview_sessions.interviewer_id` **dan** pivot `interview_session_interviewers` | Pewawancara utama (yang memegang kendali perekaman) perlu satu kolom FK yang cepat dan wajib; anggota panel lain butuh many-to-many. Pewawancara utama selalu ikut dimasukkan ke pivot saat sesi dibuat. |
| `analysis_results.interview_session_id` unik (one-to-one) | Satu kartu skor per sesi; analisis ulang menimpa isi baris yang sama. `prompt_version` dan `model_used` disimpan supaya hasil lama tetap bisa ditelusuri dari devlog/commit. |
| `transcript_segments` menyimpan timestamp mentah (`chunk_received_at`, `inference_started_at`, `inference_ended_at`, `emitted_at`) plus `latency_ms`, `e2e_latency_ms`, `real_time_factor` | Angka turunan disimpan supaya query laporan sederhana, timestamp mentah disimpan supaya angka turunan bisa diverifikasi ulang. Ini tabel sumber untuk bab evaluasi latency. |
| `evidence_quotes.is_verified` | Hasil pemeriksaan substring kutipan LLM terhadap transkrip asli. Kutipan yang tidak ditemukan tidak ditampilkan sebagai bukti. |
| `consents` terpisah dari `interview_sessions` | Persetujuan punya `agreed_at`, IP, dan versi teks sendiri; memisahkannya membuat jejak audit tidak ikut berubah saat sesi diubah. |
| `answers` unik per (`interview_session_id`, `question_id`) | Satu jawaban per pertanyaan per sesi — rekam ulang menimpa, bukan menambah. |
