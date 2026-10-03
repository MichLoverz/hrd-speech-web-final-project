# Tools

Skrip evaluasi di luar aplikasi. Jalankan dengan venv `services/`:

```
services\.venv\Scripts\python.exe tools\wer_eval.py --self-test
```

## `wer_eval.py` — WER/CER dengan normalisasi yang eksplisit

Menghitung WER dan CER dengan `jiwer`, dan **selalu menampilkan dua angka**: mentah dan setelah normalisasi angka. Selisihnya sendiri adalah informasi yang layak dilaporkan.

```
python tools/wer_eval.py --ref naskah.txt --hyp hyp-a.txt hyp-b.txt --labels a b
python tools/wer_eval.py --ref naskah.txt --hyp hyp.txt --show-errors 20 --json hasil.json
python tools/wer_eval.py --self-test
```

**Kenapa perlu.** "lima menit" dan "5 menit" itu sama artinya, tapi pembanding teks biasa menghitungnya sebagai satu kesalahan. Tanpa normalisasi, WER terlihat lebih buruk daripada kenyataannya.

**Kenapa dua angka.** Normalisasi yang terlalu murah hati membuat angka laporan jadi bagus tanpa sistemnya membaik. Menampilkan mentah dan hasil normalisasi sekaligus membuat pengaruhnya kelihatan, bukan tersembunyi.

**Yang dinormalisasi:** bilangan pokok bahasa Indonesia, termasuk bentuk campuran yang sering keluar dari ASR — `lima` → 5, `dua puluh lima` → 25, `80 juta` → 80000000, `Rp 1.000.000` → 1000000.

**Yang sengaja tidak:** bilangan tingkat (`pertama`), pecahan, dan satuan. Memetakannya butuh keputusan yang diam-diam mengubah apa yang sedang diukur.

Angka yang salah tetap salah: *delapan juta* → *80 juta* menjadi 8000000 vs 80000000, tetap dihitung sebagai kesalahan. Normalisasi memaafkan cara penulisan, bukan nilai.

**`--punct`** menentukan perlakuan tanda baca: `remove` (bawaan) menghapusnya, jadi `query-nya` → `querynya`; `space` menggantinya dengan spasi. Bawaannya `remove` karena semua angka yang tercatat sebelum 2026-09-23 dihitung begitu — menggantinya diam-diam akan membuat baris lama dan baru di `asr-runs.csv` tidak sebanding.

**Catatan sejarah.** Sebelum berkas ini ada, WER dihitung tanpa tanda baca tetapi CER dihitung dengan tanda baca. Semua CER sebelum 2026-09-23 karenanya terlalu besar. Kolom `cer_num` di `asr-runs.csv` memakai normalisasi yang sama dengan WER.

## `latency_report.py`

Belum ada. Agregasi `transcript_segments` untuk laporan latency (Fase 6).
