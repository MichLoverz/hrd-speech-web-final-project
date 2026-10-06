# Panduan prototype testing HireNote

Dari laptop mati sampai kartu skor muncul. Naskah demo dua orang ada di [skenario-demo.md](skenario-demo.md).

**Alur singkat:** nyalakan sistem → siapkan sesi demo → wawancara direkam live → halaman sesi → "Buat kartu skor" → baca hasilnya.

**Yang dibutuhkan:** laptop ini, Google Chrome, mikrofon (bawaan laptop bisa, mikrofon eksternal lebih baik), koneksi internet (untuk Gemini), dua teman, ruangan yang cukup tenang.

---

## 1. Sehari sebelum presentasi

1. Nyalakan sistem (bagian 2).
2. Cek kuota Gemini (memakai 1 dari 20 jatah harian):
   ```powershell
   cd web
   php artisan llm:ping
   ```
   Harus muncul `OK: key works ...`. Kalau muncul "Kuota harian habis", lihat bagian 8.
3. Siapkan sesi demo (bagian 3).
4. **Gladi resik sekali penuh** dengan kedua temanmu, dari wawancara sampai kartu skor (memakai 1 jatah Gemini).
5. Setelah gladi, jalankan lagi `php artisan hirenote:prototype-demo` supaya sesi demo kembali kosong untuk hari-H.

Gladi ini sekaligus **uji dua manusia** yang masih tertunda. Catat hasilnya (bagian 10).

---

## 2. Menyalakan sistem

Butuh XAMPP dan **tiga terminal** (PowerShell), semuanya dari folder proyek.

**a. Database.** Buka XAMPP Control Panel, klik **Start** di baris **MySQL**. Apache tidak perlu.

**b. Terminal 1: layanan suara (ASR) dan NLP**
```powershell
cd services
.venv\Scripts\activate
uvicorn app.main:app --host 127.0.0.1 --port 8765
```
Tunggu sampai muncul `[asr] models loaded ...` (sekitar 5–20 detik). Biarkan terminal ini terbuka. Untuk mengecek, buka <http://127.0.0.1:8765/health> di browser; harus ada `"ok":true`.

**c. Terminal 2: website**
```powershell
cd web
php artisan serve
```
Website ada di <http://127.0.0.1:8000>.

**d. Terminal 3: antrean (untuk tombol "Buat kartu skor")**
```powershell
cd web
php artisan queue:work
```
Terminal ini akan menampilkan baris `GenerateScorecard ... DONE` setiap kali kartu skor selesai dibuat.

> Buka website **hanya** lewat `http://127.0.0.1:8000` atau `http://localhost:8000`. Chrome hanya mengizinkan mikrofon di alamat itu (atau HTTPS).
>
> Kalau halaman tampil tanpa tampilan rapi (CSS hilang), jalankan sekali `npm run build` di folder `web`.

---

## 3. Menyiapkan sesi demo

```powershell
cd web
php artisan hirenote:prototype-demo
```

Perintah ini membuat:
- lowongan **Backend Developer Junior (Demo)** dengan 2 kriteria dan 2 pertanyaan (Pengalaman teknis, bobot 2; Kerja tim, bobot 1),
- kandidat rekaan **Raka Pratama (demo)**,
- **sesi draf bertanda data uji**, siap direkam.

Terminal akan mencetak alamat sesinya, misalnya `http://127.0.0.1:8000/interview-sessions/7`. Perintah ini aman dijalankan berulang kali; setiap kali dijalankan, demo sebelumnya dihapus dan dibuat ulang dari kosong.

**Kalau ingin membuat sesi sendiri lewat website** (misalnya untuk tester lain): buat lowongan, lalu kriteria dan pertanyaannya, lalu kandidat. Setelah itu buat sesi lewat **Sesi wawancara → Buat sesi** dan **centang "Sesi uji / demo"**. Tanpa centang itu, Gemini gratis akan menolak membuat kartu skornya (ADR-009). Centang hanya kalau isinya naskah atau rekaan, bukan kandidat sungguhan.

---

## 4. Merekam wawancara

1. Buka <http://127.0.0.1:8000> dan login sebagai **HRD**: `hrd@hirenote.test`, sandi `password`.
2. Buka alamat sesi demo dari bagian 3 (atau **Sesi wawancara** → sesi "Raka Pratama (demo)").
3. Klik **Mulai wawancara**. Halaman **persetujuan** muncul. Tunjukkan teksnya kepada "kandidat", centang kotak persetujuan, lalu klik **Catat persetujuan dan mulai**.
4. Halaman **wawancara live** terbuka:
   - kiri: daftar pertanyaan (pewawancara membacanya dari sini),
   - kanan: transkrip yang berjalan.
5. Letakkan laptop di antara kedua teman, sekitar 50 cm dari masing-masing.
6. Klik **Mulai wawancara**. Saat Chrome meminta izin mikrofon, klik **Izinkan**. Tunggu sampai muncul tulisan **Mendengarkan…** dan bar "mic" bergerak saat ada suara.
7. Jalankan naskah (lihat skenario-demo.md). Yang terlihat di layar selama wawancara:
   - Teks **abu-abu** = tebakan sementara, yang lalu berganti menjadi teks **hitam** (final) setelah orang berhenti bicara.
   - Label **Pewawancara** atau **Kandidat** di kiri setiap baris diberikan otomatis dari suara.
   - Pertanyaan aktif di panel kiri berpindah sendiri saat pewawancara membacakan pertanyaan atau berkata "lanjut ke pertanyaan berikutnya".
   - Kalau pertanyaannya tidak berpindah, klik **Pertanyaan berikutnya** (atau klik pertanyaannya langsung).
8. Setelah kalimat penutup, klik **Akhiri wawancara** → **OK**. Tunggu tulisan "Menunggu transkrip terakhir…" (beberapa detik). Browser akan pindah sendiri ke halaman sesi.

---

## 5. Memeriksa hasil rekaman

Di halaman sesi:
- Transkrip sudah dibagi per pertanyaan, hanya berisi jawaban kandidat.
- Kalau ada label pembicara yang salah, atau kalimat masuk ke pertanyaan yang salah, perbaiki lewat panel koreksi di halaman ini. Jawaban akan disusun ulang. **Lakukan ini sebelum membuat kartu skor**, karena kartu skor dibuat dari jawaban.
- Kalau rekamannya gagal total, tombol merah **Ulangi rekaman** di bagian atas menghapus rekaman dan mengembalikan sesi ke draf.

---

## 6. Membuat dan membaca kartu skor

1. Gulir ke bagian **Kartu skor AI — draf** di bawah. Akan terlihat kotak **"Mode data uji"**; itu normal selama memakai Gemini gratis.
2. Klik **Buat kartu skor**. Muncul pesan bahwa kartu skor sedang dibuat.
3. Tunggu sekitar **10–30 detik** (lihat Terminal 3), lalu **muat ulang halaman** (F5).

Cara membacanya:

| Bagian | Artinya |
|---|---|
| Paragraf paling atas | ringkasan isi jawaban (bukan keputusan terima/tolak) |
| **8,5/10** di samping kriteria | skor draf, skala 0–10 kelipatan 0,5 |
| "tidak ada bukti — tidak dinilai" | kriteria itu tidak terjawab; berbeda dengan skor 0 |
| Kalimat di bawah skor | alasan singkat |
| Kekuatan / Kelemahan | poin-poin dari AI |
| Kutipan **berstabilo kuning** | **terverifikasi**: kalimat itu benar-benar ada di transkrip |
| "ejaan berbeda dari transkrip — cek" | hampir sama; biasanya AI membetulkan salah dengar ASR. Kalimat aslinya ditampilkan di bawahnya |
| "parafrase, bukan kutipan — cek" | maknanya mirip, kata-katanya berbeda |
| Kutipan dicoret, "tidak ditemukan di transkrip" | kemungkinan karangan AI |
| `0:42–0:51` | posisi kalimat itu di rekaman |
| Rata-rata berbobot | gabungan skor dengan bobot kriteria |
| Baris kecil paling bawah | model, jumlah token, lama proses |

Alternatif tanpa tombol dan tanpa Terminal 3: `php artisan hirenote:scorecard <id-sesi>`. Hasilnya langsung tercetak di terminal.

Semua ini adalah **draf**. Keputusan tetap di tangan pewawancara, dan halaman sunting/setujui masih dalam pengerjaan.

---

## 7. Kalau ada masalah

| Gejala | Penyebab | Solusi |
|---|---|---|
| Halaman error `SQLSTATE ... 2002` | MySQL belum jalan | Start MySQL di XAMPP |
| "Layanan transkripsi tidak tersedia" di halaman live | Terminal 1 belum jalan atau belum selesai memuat | jalankan atau tunggu, lalu klik **Cek ulang layanan** |
| Chrome tidak meminta izin mikrofon, atau bar mic diam | izin diblokir, atau alamatnya bukan 127.0.0.1/localhost | klik ikon gembok di address bar → Mikrofon → Izinkan; cek mikrofon yang dipilih di pengaturan Windows |
| Semua baris berlabel sama, atau label tertukar | suara kedua orang terlalu mirip, atau tidak ada jeda antar-giliran | perbaiki di panel koreksi, atau **Ulangi rekaman** dengan jeda 1 detik yang lebih disiplin |
| Pertanyaan tidak pindah otomatis | pertanyaan tidak dibaca persis, atau terlalu banyak salah dengar | klik **Pertanyaan berikutnya** |
| Status "Sedang dibuat…" tidak selesai-selesai | Terminal 3 (`queue:work`) tidak jalan | jalankan, tunggu, lalu refresh |
| "Percobaan terakhir gagal: ... 503 ... high demand" | server Gemini sedang penuh | tunggu 1 menit, klik **Buat ulang** |
| "Kuota harian penyedia LLM habis ..." | jatah 20/hari sudah terpakai | lihat bagian 8 |
| "Penyedia LLM yang aktif hanya boleh dipakai untuk data uji" | sesi tidak dicentang "Sesi uji / demo" | buat sesi baru dengan centang itu, atau pakai `hirenote:prototype-demo` |
| "Layanan NLP ..." / `NlpServiceUnavailable` | Terminal 1 mati | jalankan Terminal 1, lalu buat ulang |

---

## 8. Kuota Gemini gratis

- **20 permintaan per hari per model.** Satu kali klik "Buat kartu skor" = 1 permintaan; `llm:ping` juga 1.
- Waktu pulihnya belum pasti: pesan error menyebut beberapa jam, sementara dokumentasi Google menyebut tengah malam waktu Pasifik (±14.00 WIB). Jangan menghabiskan kuota di hari presentasi.
- **Cadangan:** model lain punya jatah sendiri. Di `web/.env` ganti `LLM_MODEL=gemini-3.5-flash` menjadi `LLM_MODEL=gemini-3.5-flash-lite`, lalu **restart Terminal 3** (`Ctrl+C`, jalankan lagi `php artisan queue:work`).

---

## 9. Rencana cadangan saat presentasi

1. **Rekaman live gagal**: klik **Ulangi rekaman** dan ulangi sekali.
2. **Pembedaan suara kacau**: lanjutkan saja; tunjukkan panel koreksi sebagai fitur ("manusia tetap bisa membetulkan").
3. **Gemini tidak bisa dihubungi atau kuota habis**: tunjukkan sesi **"Data uji — naskah bacaan"** (dari `php artisan hirenote:demo-session`), yang kartu skornya sudah tersimpan dan tidak butuh Gemini untuk ditampilkan.
4. **Mode rekam per pertanyaan** (lebih sederhana, tanpa pembedaan suara): di halaman persetujuan, klik **ganti** di samping tulisan "Mode" sebelum mencatat persetujuan. Kalau persetujuan sudah tercatat, klik tautan "mode rekam per pertanyaan".

Sebaiknya ambil tangkapan layar kartu skor dari gladi resik sebagai cadangan terakhir.

---

## 10. Setelah gladi dan demo: catat untuk laporan

Gladi dengan dua orang ini adalah **uji dua manusia** yang tertunda sejak Fase 3b. Catat di `docs/experiments/raw/mic-tests/` (satu berkas per percobaan):
- tanggal, ruangan, mikrofon, siapa pewawancara dan siapa kandidat,
- dari semua baris, **berapa label pembicara yang salah** (sebelum dikoreksi),
- apakah pertanyaan berpindah otomatis, dan lewat apa (cocok teks / frasa / manual: terlihat di panel kiri "Terakhir dipindah"),
- angka latency yang tampil,
- skor kartu skor dan kutipan mana yang tidak berstabilo.

Sesi demo bertanda data uji, jadi tidak masuk angka evaluasi kartu skor. Tapi label pembicara dan pergantian pertanyaannya tetap sah sebagai bukti diarization, karena itu dua suara manusia sungguhan.

---

## 11. Mematikan

`Ctrl+C` di ketiga terminal, lalu **Stop** MySQL di XAMPP.
