# Naskah 02 — "Bagaimana Anda menangani query database yang lambat?"

Jawaban untuk pertanyaan ke-2 lowongan Backend Developer (kriteria Pengalaman teknis). Dipakai untuk uji perekaman di browser (Fase 3) dan bisa dibaca ulang di kondisi lain. **[jeda]** = berhenti ±1 detik.

> Biasanya saya mulai dari mengukur dulu, bukan menebak. **[jeda]**
> Saya cek slow query log dan jalankan EXPLAIN untuk melihat apakah query-nya melakukan full table scan. **[jeda]**
> Kalau iya, langkah pertama biasanya menambahkan index yang tepat, misalnya composite index di kolom yang dipakai di WHERE dan ORDER BY. **[jeda]**
> Kalau masalahnya ada di aplikasi, sering kali itu N plus satu query, jadi saya pakai eager loading dengan with di Laravel supaya jadi satu query saja. **[jeda]**
> Untuk data yang jarang berubah, saya tambahkan caching di Redis dengan waktu kedaluwarsa sekitar lima menit. **[jeda]**
> Setelah itu saya ukur lagi, karena optimasi tanpa angka sebelum dan sesudah itu cuma perasaan.

Versi polos: `naskah-02-query-lambat.txt`.
