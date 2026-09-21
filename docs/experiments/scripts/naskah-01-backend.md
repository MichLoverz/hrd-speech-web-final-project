# Naskah 01 — jawaban wawancara Backend Developer

Dipakai sebagai teks bacaan untuk uji ASR. Membaca naskah yang sama di kondisi berbeda (ruang sunyi, derau kantor, model berbeda, mikrofon berbeda) membuat hasilnya bisa dibandingkan. Sengaja mengandung campur kode Indonesia–Inggris, angka, istilah teknis, dan nama.

**[jeda]** = berhenti ±1 detik supaya VAD memotong kalimat.

Referensi (109 kata setelah normalisasi):

> Selamat pagi, nama saya Michael, saya melamar untuk posisi Backend Developer. **[jeda]**
> Di proyek terakhir, saya memimpin migrasi database dari MySQL ke PostgreSQL selama tiga bulan, dengan tim berlima. **[jeda]**
> Tantangan terbesarnya adalah query yang lambat di tabel transaksi, jadi saya menambahkan index dan mengubah beberapa query menjadi batch processing. **[jeda]**
> Bug paling sulit yang pernah saya temukan adalah race condition di payment queue. **[jeda]**
> Saya menemukannya dengan menambahkan structured logging, lalu membandingkan timestamp dari dua worker yang berjalan bersamaan. **[jeda]**
> Kalau code review saya ditolak, biasanya saya tanya dulu alasannya, karena seringkali reviewer melihat edge case yang saya lewatkan. **[jeda]**
> Untuk ekspektasi gaji, saya mengharapkan sekitar delapan juta rupiah, tapi saya terbuka untuk diskusi.

Catatan: naskah dibaca ≠ bicara spontan. WER dari naskah cenderung lebih rendah (lebih baik) daripada wawancara asli. Untuk baseline laporan tetap dibutuhkan rekaman spontan yang ditranskripsi manual.

Versi teks polos untuk `tools/wer_eval.py`: `naskah-01-backend.txt`.
