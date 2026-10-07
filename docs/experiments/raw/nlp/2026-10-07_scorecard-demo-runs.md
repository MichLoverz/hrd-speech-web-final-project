# 2026-10-07 — Kartu skor pada sesi demo (data uji), Gemini 3.5 Flash

**Data uji, `for_report = no`.** Sesi #5 dibuat oleh `php artisan hirenote:demo-session`. Isinya keluaran ASR asli (`small`) dari mahasiswa membaca naskah-01 (uji pertama, dengan timing per segmen) dan naskah-02 (uji kedua). Pembagian ke pertanyaan dilakukan manual. Ini bacaan, bukan wawancara, jadi tidak boleh dipakai untuk angka evaluasi. Gunanya hanya untuk memeriksa bahwa pipeline bekerja dan melihat perilaku model.

- Penyedia: Gemini API tier gratis, `gemini-3.5-flash`, reasoning `low`, temperature 0,2
- Prompt: `v1` (ADR-010)
- Jawaban: 4 dari 5 pertanyaan; "Jelaskan apa itu API" sengaja kosong
- Run 2 dan 3 sempat gagal karena 503 setelah 3 kali percobaan ulang, lalu diulang dengan `LLM_RETRY_BASE_MS=8000 LLM_RETRIES=4`. Latensi yang tercatat **termasuk waktu tunggu percobaan ulang** (karena itu retrieval tercatat 108 s)

## Ringkasan

| Run | Kondisi | Teknis | Pemecahan masalah | Komunikasi | Kerja tim | Kutipan | Token masuk/keluar |
|---|---|---|---|---|---|---|---|
| 1 | penuh | 5 | 4 | tidak ada bukti | 3 | 5 exact | 1167 / 907 |
| 2 | penuh | 5 | 4 | tidak ada bukti | 3 | 5 exact | 1167 / 932 |
| 3 | penuh | 5 | 4 | tidak ada bukti | 3 | 5 exact | 1167 / 875 |
| 4 | retrieval | 5 | 5 | tidak ada bukti | **tidak ada bukti** | 4 exact | 1739 / 866 |

## Bacaan

1. **Aturan "salin persis termasuk salah dengarnya" dipatuhi**: 19 dari 19 kutipan di empat run `exact`, tanpa satu pun karangan atau "pembetulan" ASR.
2. **Komunikasi diberi "tidak ada bukti", bukan angka tebakan**, di semua run.
3. **Skor stabil** di tiga run kondisi penuh (temperature 0,2).
4. **Temuan utama: kutipan yang terverifikasi belum tentu tafsirannya benar.** Jawaban code review di transkrip berbunyi *"kalau 4nya bisa ditabar, bisa bisa ditanyain 2-3nya karena sering kali refiur mulia etxkesi yang saya lewatkan"*. Model menafsirkannya sebagai "reviewer melihat kasus ekstrem yang terlewat" dan memberi skor 3. Tafsiran itu kebetulan benar (naskah aslinya memang menyebut *reviewer* dan *edge case*), tapi diperoleh dengan menebak dari konteks pertanyaan. Ini melanggar aturan 2 di prompt ("kalau terlalu rusak, katakan, jangan menebak"). Kutipannya sendiri `exact`, jadi verifikasi tidak bisa menangkapnya. Verifikasi menjamin kata-katanya ada di transkrip, **bukan** bahwa maknanya terbaca.
5. **Kondisi retrieval mengubah hasil dengan cara yang menjelaskan temuan nomor 4.** Kalimat code review yang sama ikut terambil untuk Kerja tim (peringkat 5, cosine 0,155), tetapi tanpa teks pertanyaannya. Tanpa konteks itu, model tidak bisa menebak artinya dan menyatakan "tidak ada bukti". Jadi di kondisi penuh, teks pertanyaan dipakai model untuk "menerjemahkan" ASR yang rusak.
6. **Retrieval tidak menghemat token untuk sesi pendek** (1739 vs 1167), karena kalimat yang sama muncul berulang di beberapa kriteria. Penghematannya baru terasa pada transkrip 30 menit.
7. **Retrieval selalu mengambil top-5 walaupun tidak relevan.** Untuk Komunikasi, cosine tertingginya hanya 0,17. Ambang minimum relevansi layak dipertimbangkan.
8. Skor 5 untuk Pengalaman teknis cenderung murah hati. Jangkar skor 5 meminta "hasil terukur", sedangkan jawabannya hampir tidak menyebut hasil (hanya "3 bulan" dan cache "5 menit"). Ini perlu dibandingkan dengan penilai manusia, bukan diputuskan di sini.

## Log lengkap

### run1

```
Sesi #5 · DATA UJI · kondisi full

Ringkasan: Kandidat menunjukkan pemahaman teknis yang kuat dalam pengembangan backend, khususnya dalam optimasi database PostgreSQL/MySQL dan penanganan masalah performa seperti query lambat dan race condition. Penjelasan mengenai pemecahan masalah dan kerja tim disampaikan dengan contoh konkret, meskipun terdapat beberapa kesalahan transkripsi pada teks jawaban.

■ Pengalaman teknis: 5
  Kandidat memberikan contoh konkret migrasi database dan menjelaskan langkah optimasi query lambat secara sangat detail, terstruktur, dan menggunakan metode terukur seperti slow query log dan explain.
  [exact] di proyek terakhir saya memimpin migrasi database dari MySQL ke PostgreSQL selama 3 bulan dengan dimatikan orang Tantang entera besar nya adalah kweri yang lambat
  [exact] Saya cek slow query log dan jalankan explain untuk apakah query-nya melakukan full table scan Kalau iya, langkah pertama biasanya menabakan index yang tepat.
  [exact] Kalau masalahnya ada di aplikasi sering kali itu n plus satu query. Jadi saya pakai eager loading dengan width di Laravel supaya jadi satu query saja
■ Pemecahan masalah: 4
  Kandidat menceritakan bug sulit berupa race condition pada antrean pembayaran dan menjelaskan langkah diagnosis yang logis menggunakan structured logging dan analisis timestamp.
  [exact] bagi yang paling sulit yang saya pernah temukan adalah race condition di payment QE Saya menemukannya dengan nambahkan Structure Logging lalu membandingkan timestamp dari dua worker
■ Komunikasi: tidak ada bukti
  Tidak ada jawaban atau bukti dalam transkrip yang berkaitan dengan kriteria komunikasi untuk menjelaskan konsep teknis (API) kepada orang non-teknis.
■ Kerja tim: 3
  Kandidat menunjukkan sikap terbuka dalam menerima masukan dari code review untuk melihat sudut pandang atau kasus ekstrem yang terlewat, meskipun penjelasannya cukup singkat.
  [exact] kalau 4nya bisa ditabar, bisa bisa ditanyain 2-3nya karena sering kali refiur mulia etxkesi yang saya lewatkan

Kutipan: exact 5 · normalized 0 · fuzzy 0 · similar 0 · none 0 · dibuang 0 · pindah jawaban 0
Model gemini-3.5-flash · token in 1167 / out 907 · 6406 ms · prompt v1
```

### run2

```
Sesi #5 · DATA UJI · kondisi full

Ringkasan: Kandidat menunjukkan pemahaman teknis yang kuat dalam pengembangan backend, khususnya optimasi database PostgreSQL/MySQL dan penanganan masalah performa di Laravel. Kandidat juga memiliki pengalaman dalam mendiagnosis bug kompleks seperti race condition menggunakan structured logging, meskipun penjelasan mengenai komunikasi non-teknis tidak tercakup dalam transkrip ini.

■ Pengalaman teknis: 5
  Kandidat memberikan contoh konkret mengenai migrasi database dan optimasi query lambat dengan langkah-langkah yang sangat jelas, terukur, dan menggunakan praktik terbaik seperti indexing, batch processing, penanganan N+1 query dengan eager loading, serta caching menggunakan Redis.
  [exact] di proyek terakhir saya memimpin migrasi database dari MySQL ke PostgreSQL selama 3 bulan dengan dimatikan orang Tantang entera besar nya adalah kweri yang lambat
  [exact] Saya cek slow query log dan jalankan explain untuk apakah query-nya melakukan full table scan Kalau iya, langkah pertama biasanya menabakan index yang tepat.
  [exact] Kalau masalahnya ada di aplikasi sering kali itu n plus satu query. Jadi saya pakai eager loading dengan width di Laravel supaya jadi satu query saja
■ Pemecahan masalah: 4
  Kandidat menceritakan bug sulit berupa race condition pada antrean pembayaran dan menjelaskan metode diagnosis yang logis menggunakan structured logging serta analisis timestamp.
  [exact] bagi yang paling sulit yang saya pernah temukan adalah race condition di payment QE Saya menemukannya dengan nambahkan Structure Logging lalu membandingkan timestamp dari dua worker
■ Komunikasi: tidak ada bukti
  Tidak ada jawaban atau bukti dalam transkrip yang berkaitan dengan pertanyaan penjelasan API kepada orang non-teknis.
■ Kerja tim: 3
  Kandidat menunjukkan sikap terbuka terhadap penolakan code review dan menyadari bahwa reviewer sering kali melihat sudut pandang atau kasus ekstrem yang terlewat, meskipun penjelasannya cukup singkat.
  [exact] kalau 4nya bisa ditabar, bisa bisa ditanyain 2-3nya karena sering kali refiur mulia etxkesi yang saya lewatkan

Kutipan: exact 5 · normalized 0 · fuzzy 0 · similar 0 · none 0 · dibuang 0 · pindah jawaban 0
Model gemini-3.5-flash · token in 1167 / out 932 · 23619 ms · prompt v1
```

### run3

```
Sesi #5 · DATA UJI · kondisi full

Ringkasan: Kandidat menunjukkan pemahaman teknis yang kuat dalam pengembangan backend, khususnya dalam optimasi database PostgreSQL/MySQL dan penanganan masalah performa seperti query lambat dan race condition. Penjelasan mengenai pemecahan masalah dan kerja tim disampaikan dengan contoh konkret, meskipun terdapat beberapa kesalahan transkripsi pada teks jawaban.

■ Pengalaman teknis: 5
  Kandidat memberikan contoh konkret migrasi database dan menjelaskan langkah optimasi query lambat secara sangat detail, terstruktur, dan menggunakan metode terukur seperti slow query log dan explain.
  [exact] di proyek terakhir saya memimpin migrasi database dari MySQL ke PostgreSQL
  [exact] Saya cek slow query log dan jalankan explain untuk apakah query-nya melakukan full table scan
  [exact] Kalau masalahnya ada di aplikasi sering kali itu n plus satu query. Jadi saya pakai eager loading dengan width di Laravel
■ Pemecahan masalah: 4
  Kandidat menceritakan bug sulit berupa race condition pada antrean pembayaran dan menjelaskan metode diagnosis yang jelas menggunakan structured logging dan analisis timestamp.
  [exact] bagi yang paling sulit yang saya pernah temukan adalah race condition di payment QE Saya menemukannya dengan nambahkan Structure Logging
■ Komunikasi: tidak ada bukti
  Tidak ada pertanyaan atau jawaban dalam transkrip yang membahas penjelasan konsep API kepada orang non-teknis.
■ Kerja tim: 3
  Kandidat menunjukkan sikap terbuka terhadap penolakan code review untuk melihat sudut pandang reviewer, namun penjelasannya sangat singkat dan terdapat bagian transkrip yang kurang jelas maknanya.
  [exact] kalau 4nya bisa ditabar, bisa bisa ditanyain 2-3nya karena sering kali refiur mulia etxkesi yang saya lewatkan

Kutipan: exact 5 · normalized 0 · fuzzy 0 · similar 0 · none 0 · dibuang 0 · pindah jawaban 0
Model gemini-3.5-flash · token in 1167 / out 875 · 6822 ms · prompt v1
```

### retrieval

```
Sesi #5 · DATA UJI · kondisi retrieval

Ringkasan: Kandidat menunjukkan pemahaman teknis backend yang kuat, khususnya dalam optimasi database PostgreSQL/MySQL dan framework Laravel. Kandidat mampu menjelaskan langkah-langkah konkret dalam mendiagnosis query lambat dan menangani bug rumit seperti race condition. Namun, transkrip tidak memuat jawaban yang relevan untuk kriteria komunikasi (penjelasan API kepada non-programmer) dan kerja tim (tanggapan terhadap penolakan code review).

■ Pengalaman teknis: 5
  Kandidat memiliki pengalaman konkret dalam memimpin migrasi database dan mampu menjelaskan teknik optimasi query secara mendalam, termasuk penggunaan index, eager loading di Laravel, dan caching Redis.
  [exact] di proyek terakhir saya memimpin migrasi database dari MySQL ke PostgreSQL selama 3 bulan dengan dimatikan orang Tantang entera besar nya adalah kweri yang lambat di label transseksi.
  [exact] Jadi saya pakai eager loading dengan width di Laravel supaya jadi satu query saja Untuk data yang jarang berubah, saya tambahkan caching di redis dengan waktu kada luars sekitar 5 menit.
■ Pemecahan masalah: 5
  Kandidat menunjukkan kemampuan pemecahan masalah yang sangat baik dengan pendekatan berbasis data (mengukur sebelum menebak) dan berhasil menyelesaikan masalah race condition menggunakan structured logging.
  [exact] bagi yang paling sulit yang saya pernah temukan adalah race condition di payment QE Saya menemukannya dengan nambahkan Structure Logging lalu membandingkan timestamp dari dua worker yang berjalan bersamaan.
  [exact] besarnya saya mulai dari mengukur dulu bukan menembak Saya cek slow query log dan jalankan explain untuk apakah query-nya melakukan full table scan Kalau iya, langkah pertama biasanya menabakan index yang tepat.
■ Komunikasi: tidak ada bukti
  Tidak ada bukti atau jawaban dalam transkrip yang menunjukkan penjelasan kandidat mengenai apa itu API kepada orang non-teknis.
■ Kerja tim: tidak ada bukti
  Tidak ada bukti atau jawaban dalam transkrip yang memadai mengenai bagaimana kandidat menanggapi penolakan code review atau berkolaborasi dalam tim.

Kutipan: exact 4 · normalized 0 · fuzzy 0 · similar 0 · none 0 · dibuang 0 · pindah jawaban 0
Model gemini-3.5-flash · token in 1739 / out 866 · 108675 ms · prompt v1
```

## Tambahan: prompt v2 (skala 0–10)

Dua run identik: 8,5 / 7,5 / tidak ada bukti / 5, 4 kutipan exact, 0 skor dibulatkan. Run ketiga ditolak dengan **429: kuota harian tier gratis `gemini-3.5-flash` = 20 permintaan per hari per model** (`GenerateRequestsPerDayPerProjectPerModel-FreeTier`, pulih ±2,5 jam kemudian).

### v2 run 1

```
Sesi #5 · DATA UJI · kondisi full

Ringkasan: Kandidat menunjukkan pemahaman teknis yang kuat dalam pengembangan backend, khususnya dalam optimasi database PostgreSQL/MySQL dan penanganan bug kompleks seperti race condition. Penjelasan mengenai penanganan query lambat sangat terstruktur dengan langkah-langkah konkret, meskipun tanggapan terkait kerja tim dan komunikasi non-teknis tidak tergali sepenuhnya karena keterbatasan transkrip.

■ Pengalaman teknis: 8,5/10
  Kandidat memiliki pengalaman konkret dalam migrasi database dan memberikan langkah-langkah optimasi query yang sangat jelas, mulai dari menganalisis slow query log hingga menerapkan indexing dan caching.
  [exact] Saya cek slow query log dan jalankan explain untuk apakah query-nya melakukan full table scan Kalau iya, langkah pertama biasanya menabakan index yang tepat.
  [exact] Kalau masalahnya ada di aplikasi sering kali itu n plus satu query. Jadi saya pakai eager loading dengan width di Laravel supaya jadi satu query saja
■ Pemecahan masalah: 7,5/10
  Kandidat menceritakan bug sulit berupa race condition pada antrean pembayaran dan menjelaskan metode pelacakan menggunakan structured logging dan perbandingan timestamp secara logis.
  [exact] bagi yang paling sulit yang saya pernah temukan adalah race condition di payment QE Saya menemukannya dengan nambahkan Structure Logging lalu membandingkan timestamp
■ Komunikasi: tidak ada bukti
  Tidak ada bukti atau jawaban dari kandidat mengenai pertanyaan penjelasan API kepada orang non-programmer dalam transkrip yang disediakan.
■ Kerja tim: 5/10
  Kandidat menunjukkan sikap terbuka terhadap penolakan code review untuk melihat sudut pandang reviewer yang terlewat, namun transkrip jawaban sangat singkat dan terdapat kata-kata yang kurang jelas akibat kesalahan transkripsi.
  [exact] kalau 4nya bisa ditabar, bisa bisa ditanyain 2-3nya karena sering kali refiur mulia etxkesi yang saya lewatkan

Kutipan: exact 4 · normalized 0 · fuzzy 0 · similar 0 · none 0 · dibuang 0 · pindah jawaban 0
Model gemini-3.5-flash · token in 1197 / out 869 · 5670 ms · prompt v2
```

### v2 run 2

```
Sesi #5 · DATA UJI · kondisi full

Ringkasan: Kandidat menunjukkan pemahaman teknis yang kuat dalam pengembangan backend, khususnya dalam optimasi database PostgreSQL/MySQL dan penanganan bug kompleks seperti race condition. Penjelasan mengenai penanganan query lambat sangat terstruktur dan menggunakan metode yang terukur, meskipun tanggapan terkait kerja tim dan komunikasi non-teknis masih sangat singkat.

■ Pengalaman teknis: 8,5/10
  Kandidat memberikan contoh konkret migrasi database dan menjelaskan langkah-langkah optimasi query lambat secara sistematis menggunakan explain, indexing, eager loading, dan caching.
  [exact] Saya cek slow query log dan jalankan explain untuk apakah query-nya melakukan full table scan Kalau iya, langkah pertama biasanya menabakan index yang tepat.
  [exact] Kalau masalahnya ada di aplikasi sering kali itu n plus satu query. Jadi saya pakai eager loading dengan width di Laravel
■ Pemecahan masalah: 7,5/10
  Kandidat menceritakan bug sulit berupa race condition pada antrean pembayaran dan menjelaskan metode penyelesaiannya menggunakan structured logging secara jelas.
  [exact] bagi yang paling sulit yang saya pernah temukan adalah race condition di payment QE Saya menemukannya dengan nambahkan Structure Logging lalu membandingkan timestamp
■ Komunikasi: tidak ada bukti
  Tidak ada bukti atau jawaban dari kandidat untuk pertanyaan mengenai penjelasan API kepada orang non-teknis.
■ Kerja tim: 5/10
  Kandidat menunjukkan sikap terbuka terhadap code review, namun penjelasannya sangat singkat dan terdapat beberapa kata yang rusak akibat transkripsi sehingga kurang jelas.
  [exact] kalau 4nya bisa ditabar, bisa bisa ditanyain 2-3nya karena sering kali refiur mulia etxkesi yang saya lewatkan

Kutipan: exact 4 · normalized 0 · fuzzy 0 · similar 0 · none 0 · dibuang 0 · pindah jawaban 0
Model gemini-3.5-flash · token in 1197 / out 845 · 5263 ms · prompt v2
```

## Tambahan: `gemini-3.5-flash-lite`, prompt v2

Dijalankan karena kuota `gemini-3.5-flash` habis. Hasil: **7,5 / 7,5 / tidak ada bukti / 5**, 3 kutipan exact (satu per kriteria, lebih sedikit dari `flash` yang memberi 4), 3,1 detik, 650 token keluar. Format JSON dan aturan kutipan tetap dipatuhi. Dibanding `flash` (8,5 / 7,5 / – / 5), hanya Pengalaman teknis yang berbeda (satu langkah 1 poin). Satu run, jadi kestabilannya belum diketahui.

**Soal kuota (koreksi):** pada 2026-10-07 pukul 09.17 WIB, kuota `flash` masih habis walaupun aplikasi tidak membuat satu permintaan pun sejak 04.30 WIB. Padahal `retryDelay` pukul 04.30 menunjuk ke 07.00 WIB (tengah malam UTC). Jadi tidak ada reset pada jam itu. Dugaan: hitungan harian memakai waktu Pasifik (reset ±14.00 WIB), sementara `retryDelay` dihitung sampai tengah malam UTC. Belum dibuktikan; akan dicek dengan `llm:ping` setelah 14.00 WIB.

```
Sesi #5 · DATA UJI · kondisi full

Ringkasan: Kandidat menjelaskan pengalaman migrasi database dan optimasi query menggunakan Laravel dan PostgreSQL dengan cukup baik. Kandidat juga menunjukkan pemahaman teknis dalam menangani bug sulit seperti race condition dan menyikapi code review secara terbuka.

■ Pengalaman teknis: 7,5/10
  Kandidat memberikan contoh konkret mengenai migrasi database dan langkah-langkah optimasi query yang lambat menggunakan index, eager loading, serta caching.
  [exact] Saya cek slow query log dan jalankan explain untuk apakah query-nya melakukan full table scan
■ Pemecahan masalah: 7,5/10
  Kandidat mampu mendiagnosis bug sulit berupa race condition dengan menggunakan structured logging dan analisis timestamp.
  [exact] Saya menemukannya dengan nambahkan Structure Logging lalu membandingkan timestamp dari dua worker
■ Komunikasi: tidak ada bukti
  Tidak ada jawaban yang membahas atau memberikan bukti terkait penjelasan API kepada orang non-teknis.
■ Kerja tim: 5/10
  Kandidat menunjukkan sikap terbuka terhadap masukan dalam code review meskipun penjelasan transkrip pada bagian ini agak kurang jelas.
  [exact] kalau 4nya bisa ditabar, bisa bisa ditanyain 2-3nya karena sering kali refiur mulia

Kutipan: exact 3 · normalized 0 · fuzzy 0 · similar 0 · none 0 · dibuang 0 · pindah jawaban 0
Model gemini-3.5-flash-lite · token in 1197 / out 650 · 3064 ms · prompt v2
```
