# Eksperimen

- `results/asr-runs.csv` — **ledger semua run ASR**, satu baris per run, apa pun tujuannya. Kolom `for_report` = `yes` hanya untuk run yang kondisinya terkontrol dan referensinya ditranskripsi manual. Run `smoke`/`mic-test` tetap disimpan sebagai bukti perkembangan sistem dari awal.
- `raw/latency/` — log mentah per emit (CSV) + konfigurasi & mesin (JSON), ditulis otomatis oleh `services/cli/*_demo.py`.
- `raw/mic-tests/` — catatan per uji dengan suara manusia: naskah, transkrip sistem, WER, pola kesalahan, kesan subjektif.
- `raw/audio/` — rekaman (git-ignored; suara partisipan tidak masuk repo).
- `raw/fleurs/` — hasil per kalimat + ringkasan run FLEURS (`tools/fleurs_eval.py`). Korpusnya sendiri ada di `services/data/fleurs/`, git-ignored (452 MB).
- `scripts/` — naskah bacaan yang dipakai ulang lintas kondisi supaya hasil sebanding.
- `results/` — tabel jadi untuk laporan.

Aturan: tiap run baru → tambah baris di `asr-runs.csv` di sesi yang sama. Tidak ada angka di laporan yang tidak bisa ditelusuri ke baris ledger dan log mentahnya.

## Cara angka dihitung (sejak 2026-09-23)

Semua WER/CER dihitung `tools/wer_eval.py`, tidak lagi ad-hoc. Kolom di ledger:

| Kolom | Artinya |
|---|---|
| `wer`, `cer` | seperti dicatat saat run dibuat |
| `wer_num`, `cer_num` | normalisasi angka bahasa Indonesia ikut diterapkan (`lima` → 5) |
| `scorer` | alat dan konvensinya, supaya bisa dihitung ulang |

Dua hal yang perlu diketahui sebelum memakai angka lama:

1. **CER sebelum 2026-09-23 terlalu besar.** WER dulu dihitung setelah tanda baca dibuang, tapi CER dihitung pada teks yang masih bertanda baca, jadi koma dan titik sisipan Whisper terhitung sebagai kesalahan huruf. Pakai `cer_num`.
2. **Konvensi tanda baca dikunci `--punct remove`** supaya baris lama dan baru sebanding. Penghitungan ulang mereproduksi angka WER lama persis; kalau suatu saat konvensinya diganti, seluruh kolom harus dihitung ulang sekaligus.
