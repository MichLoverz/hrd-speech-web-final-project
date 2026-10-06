# Skenario demo HireNote (±2 menit)

Lowongan: **Backend Developer Junior (Demo)**, disiapkan dengan `php artisan hirenote:prototype-demo`. Semua isinya rekaan; tidak ada nama atau data asli siapa pun.

| Peran | Siapa | Tugas |
|---|---|---|
| Presenter | Michael | mengoperasikan laptop dan menjelaskan ke audiens |
| Pewawancara | Teman A | membaca bagian Pewawancara |
| Kandidat | Teman B | membaca bagian Kandidat |

## Tiga aturan membaca (penting untuk sistemnya)

1. **Jeda sekitar 1 detik** setiap kali giliran bicara berganti. Jangan menyambung atau memotong ucapan orang lain. Jeda inilah yang membuat sistem bisa memisahkan kedua suara.
2. **Pewawancara membaca pertanyaan persis** seperti tertulis di layar. Sistem memindahkan pertanyaan dengan mencocokkan ucapan itu.
3. Bicara jelas dengan kecepatan biasa, menghadap laptop, sekitar 50 cm dari laptop.

## Naskah

**Sebelum merekam.** Presenter membuka sesi → **Mulai wawancara** → halaman persetujuan. Pewawancara menunjukkan teks persetujuan ke Kandidat, Kandidat berkata "Saya setuju", lalu Presenter mencentang dan klik **Catat persetujuan dan mulai**.

**Presenter:** klik **Mulai wawancara**, izinkan mikrofon, tunggu tulisan **Mendengarkan…**, lalu beri tanda ke Pewawancara.

| # | Siapa | Ucapan |
|---|---|---|
| 1 | Pewawancara | Selamat pagi, terima kasih sudah datang hari ini. Kita mulai wawancaranya, ya. |
| 2 | Pewawancara | **Ceritakan satu masalah performa yang pernah Anda selesaikan.** |
| | | *(jeda 1 detik)* |
| 3 | Kandidat | Waktu magang, halaman laporan penjualan butuh delapan detik untuk terbuka karena query-nya membaca seluruh tabel transaksi. *(jeda 1 detik)* Saya menambahkan index pada kolom tanggal dan menyimpan hasilnya di cache, jadi waktunya turun menjadi kurang dari satu detik. |
| | | *(jeda 1 detik)* |
| 4 | Pewawancara | Baik, terima kasih. Lanjut ke pertanyaan berikutnya. |
| 5 | Pewawancara | **Bagaimana Anda menanggapi masukan dari rekan satu tim?** |
| | | *(jeda 1 detik)* |
| 6 | Kandidat | Biasanya saya dengarkan dulu masukannya dan tanya alasannya supaya saya paham. *(jeda 1 detik)* Setelah itu saya perbaiki kodenya dan minta dia mengecek ulang. |
| | | *(jeda 1 detik)* |
| 7 | Pewawancara | Baik, terima kasih atas waktunya. Wawancara kita cukup sampai di sini. |

**Presenter:** klik **Akhiri wawancara** → **OK** → tunggu sampai pindah ke halaman sesi → gulir ke **Kartu skor AI** → **Buat kartu skor** → tunggu 10–30 detik → muat ulang (F5).

## Yang ditunjukkan Presenter ke audiens

Selama wawancara:
- Transkrip muncul **saat orang masih bicara**: teks abu-abu yang lalu menjadi hitam.
- Label **Pewawancara / Kandidat** diberikan otomatis dari suara.
- Pertanyaan aktif di kiri **berpindah sendiri** setelah langkah 4–5.

Di kartu skor:
- Skor per kriteria **0–10**, ditambah rata-rata berbobot (Pengalaman teknis bobot 2, Kerja tim bobot 1).
- Kutipan **berstabilo** = kalimat yang benar-benar diucapkan kandidat, sudah dicek otomatis ke transkrip. AI tidak bisa mengarang bukti tanpa ketahuan.
- Semuanya **draf**. Keputusan tetap di tangan pewawancara.

## Kenapa jawabannya seperti ini

Kedua jawaban sengaja dibuat berbeda kualitasnya, supaya kartu skor menunjukkan bahwa AI membedakan, bukan memberi nilai sama rata:

- **Jawaban 1** punya contoh konkret, langkah, dan **hasil terukur** (8 detik → kurang dari 1 detik). Menurut patokan skala, ini mendekati skor tinggi.
- **Jawaban 2** punya langkah, tapi **tanpa contoh kejadian nyata** dan tanpa hasil. Menurut patokan, ini sekitar tengah.

Angka pastinya ditentukan model dan bisa sedikit berbeda dari gladi ke presentasi. Jangan menjanjikan angka tertentu ke audiens.

---

## Kartu untuk Pewawancara (Teman A)

> Tunggu Presenter memberi tanda. Jeda 1 detik setiap kali giliran berganti.

1. Selamat pagi, terima kasih sudah datang hari ini. Kita mulai wawancaranya, ya.
2. **Ceritakan satu masalah performa yang pernah Anda selesaikan.**
   *(tunggu Kandidat selesai, jeda 1 detik)*
3. Baik, terima kasih. Lanjut ke pertanyaan berikutnya.
4. **Bagaimana Anda menanggapi masukan dari rekan satu tim?**
   *(tunggu Kandidat selesai, jeda 1 detik)*
5. Baik, terima kasih atas waktunya. Wawancara kita cukup sampai di sini.

## Kartu untuk Kandidat (Teman B)

> Mulai bicara 1 detik setelah Pewawancara selesai membaca pertanyaan.

**Jawaban 1** (setelah pertanyaan tentang masalah performa):
Waktu magang, halaman laporan penjualan butuh delapan detik untuk terbuka karena query-nya membaca seluruh tabel transaksi.
*(jeda 1 detik)*
Saya menambahkan index pada kolom tanggal dan menyimpan hasilnya di cache, jadi waktunya turun menjadi kurang dari satu detik.

**Jawaban 2** (setelah pertanyaan tentang masukan rekan tim):
Biasanya saya dengarkan dulu masukannya dan tanya alasannya supaya saya paham.
*(jeda 1 detik)*
Setelah itu saya perbaiki kodenya dan minta dia mengecek ulang.
