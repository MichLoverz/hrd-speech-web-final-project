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

## `recorder/` — website perekam dataset fine-tune Whisper

Situs statis (HTML/JS biasa, tanpa build, tanpa backend). Pembicara membaca satu blok kalimat dari `kalimat.tsv`; tiap rekaman disimpan di browser mereka (IndexedDB) sebagai **WAV 16 kHz mono 16-bit** beserta teks yang tampil di layar. Tombol **Unduh semua (ZIP)** menghasilkan:

- `KODE_ID.wav`, satu per kalimat (mis. `P03_L2-014.wav`)
- `transkrip.tsv`: label (`teks`), durasi, level, jumlah percobaan, dan `teks_sama_dengan_terkini` (`tidak` berarti kalimatnya diubah setelah direkam, jadi rekaman perlu diulang atau labelnya dicek)
- `pembicara.json`: kode, blok, jenis kelamin, daerah/logat, mic, ruangan, waktu persetujuan, dan pemrosesan mic yang benar-benar diterapkan browser

**Tautan per pembicara:** `https://<domain>/?kode=P03&blok=L2`. Kode dan blok dari tautan dikunci di formulir. Pembagian yang disepakati: 8 pembicara latih, tiap blok L1–L4 dibaca 2 orang, dan 2 pembicara uji membaca blok U. Contohnya P01–P02 → L1, P03–P04 → L2, P05–P06 → L3, P07–P08 → L4, P09–P10 → U. Simpan daftar kode → nama **di luar repo**.

**Konvensi label** (disepakati 2026-10-27 dan 2026-11-17):
- Istilah Inggris memakai ejaan Inggris (`query`, bukan `kueri`); angka ditulis sebagai digit (`8 juta`), tetapi dibaca biasa.
- Kata pengisi **ditulis di label** dengan tiga bentuk saja: `eee`, `hmm`, `eh`. Pembicara mengucapkannya seperti tertulis dan tidak menambah sendiri. Alasannya: metrik delivery (frekuensi kata pengisi) butuh transkrip yang memuatnya, dan model hanya belajar ejaan yang ada di label.

**Pemrosesan mic** sama dengan perekam HireNote (`echoCancellation`, `noiseSuppression`, `autoGainControl` aktif), supaya data latih terdengar seperti audio yang nanti diterima model di aplikasi. Audio direkam pada laju asli browser, lalu di-resample ke 16 kHz dengan `OfflineAudioContext` (band-limited, tanpa aliasing). Ada 0,3 s pre-roll dan 0,35 s ekor supaya suku kata awal dan akhir tidak terpotong.

**Coba lokal:** `py -3.12 -m http.server 8099 --bind 127.0.0.1` dari folder `tools/recorder`, lalu buka `http://localhost:8099/?kode=P99&blok=L1`. Mikrofon hanya jalan di `localhost` atau HTTPS.

**Deploy ke Vercel:** folder ini dipublikasikan sebagai repo terpisah [MichLoverz/speech-recognition-data-gathering](https://github.com/MichLoverz/speech-recognition-data-gathering), dengan isi folder sebagai root. Di Vercel, impor repo itu dengan Framework Preset *Other* dan tanpa build command. Ubah kodenya di sini, lalu perbarui repo itu:

```
git subtree split --prefix=tools/recorder -b recorder-publish
git push data-gathering recorder-publish:main
```

**Menyimpan ZIP yang masuk:** ekstrak ke `services/data/rekaman/<KODE>/`. Folder `services/data/` sudah di-gitignore. Audio peserta tidak boleh masuk git.

## `latency_report.py`

Belum ada. Agregasi `transcript_segments` untuk laporan latency (Fase 6).
