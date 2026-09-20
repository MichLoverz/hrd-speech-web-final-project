# HireNote

Sistem penilaian wawancara terstruktur untuk rekrutmen. Merekam jawaban kandidat per pertanyaan, mentranskripsikannya **secara real-time dengan model ASR lokal**, lalu membantu HRD menyusun kartu skor yang setiap poinnya disertai kutipan transkrip sebagai bukti.

Proyek akhir untuk mata kuliah Speech Recognition (COMP6822001), Web Programming, dan Venture Creation — BINUS University, 2026/2027.

## Struktur repo

```
web/    Laravel 12 — aplikasi utama (auth, CRUD, penyimpanan transkrip & metrik, kartu skor)
asr/    Python — ASR service real-time (FastAPI + WebSocket + faster-whisper)   [Fase 2]
tools/  Harness evaluasi: WER/CER dan laporan latency                             [Fase 6]
docs/   Devlog harian, ADR, eksperimen, screenshot, AI usage log, user testing
```

## Menjalankan aplikasi web (Fase 0)

Prasyarat: PHP 8.2+, Composer, Node 20+, MySQL/MariaDB.

```bash
cd web
composer install
npm install
cp .env.example .env        # lalu isi DB_DATABASE=hirenote, DB_USERNAME, DB_PASSWORD
php artisan key:generate
php artisan migrate:fresh --seed
npm run build               # atau: npm run dev
php artisan serve
```

Akun contoh (dari seeder): `admin@hirenote.test`, `hrd@hirenote.test`, `interviewer@hirenote.test` — kata sandi `password`.

Tes: `php artisan test`.

## Prinsip yang tidak boleh dilanggar

- Tidak ada API Speech Recognition eksternal. Inference berjalan lokal.
- Transkripsi harus streaming: teks parsial muncul saat orang masih bicara.
- Tidak ada data eksperimen, hasil WER, atau user testing yang dibuat-buat.
- Rekaman wawancara dan CV tidak pernah masuk ke git.

Lihat `docs/decisions/` untuk alasan di balik keputusan arsitektur dan `docs/ai-usage-log.md` untuk catatan penggunaan AI.
