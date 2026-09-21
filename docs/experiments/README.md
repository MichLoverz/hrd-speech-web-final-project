# Eksperimen

- `results/asr-runs.csv` — **ledger semua run ASR**, satu baris per run, apa pun tujuannya. Kolom `for_report` = `yes` hanya untuk run yang kondisinya terkontrol dan referensinya ditranskripsi manual. Run `smoke`/`mic-test` tetap disimpan sebagai bukti perkembangan sistem dari awal.
- `raw/latency/` — log mentah per emit (CSV) + konfigurasi & mesin (JSON), ditulis otomatis oleh `services/cli/*_demo.py`.
- `raw/mic-tests/` — catatan per uji dengan suara manusia: naskah, transkrip sistem, WER, pola kesalahan, kesan subjektif.
- `raw/audio/` — rekaman (git-ignored; suara partisipan tidak masuk repo).
- `scripts/` — naskah bacaan yang dipakai ulang lintas kondisi supaya hasil sebanding.
- `results/` — tabel jadi untuk laporan.

Aturan: tiap run baru → tambah baris di `asr-runs.csv` di sesi yang sama. Tidak ada angka di laporan yang tidak bisa ditelusuri ke baris ledger dan log mentahnya.
