# ADR-010: Prompt kartu skor v1 dan pembuatnya

Tanggal: 2026-10-07 · Status: diterima, kecuali skala 1–5 yang menunggu persetujuan mahasiswa (§1)

## Konteks

ADR-004 menetapkan alurnya (retrieval → LLM → verifikasi kutipan → HRD menyetujui), ADR-008 verifikasinya, dan ADR-009 penyedia LLM serta persetujuan v2. Yang belum ada adalah prompt, format jawaban, dan kode yang menjalankan alur itu secara utuh.

## Keputusan

### 1. Satu skala 1–5 untuk manusia dan AI (`App\Support\ScoreScale`)

Form penilaian manual sebelumnya hanya menampilkan angka 1–5 tanpa penjelasan arti tiap angka. Kalau prompt diberi definisi skala sementara penilai manusia tidak, metrik *agreement* membandingkan dua penggaris yang berbeda. Sekarang definisi skala ditulis di satu tempat, dipakai oleh prompt, dan ditampilkan di form manual (bagian "Arti skor 1–5" serta tooltip di tiap pilihan).

| Skor | Jangkar |
|---|---|
| 1 | Sangat kurang: tidak menjawab, atau menunjukkan pemahaman yang keliru |
| 2 | Kurang: menyentuh topik tetapi umum atau dangkal, tanpa contoh konkret |
| 3 | Cukup: menjawab dengan benar dan ada contoh, tetapi peran, langkah, atau hasilnya kurang jelas |
| 4 | Baik: contoh konkret dengan langkah dan alasan yang jelas |
| 5 | Sangat baik: contoh konkret, langkah dan alasan jelas, hasil terukur, dan menyebut pertimbangan atau pelajaran |

**Jangkar ini diusulkan AI dan belum disetujui mahasiswa.** Jangkar ini harus disetujui sebelum penilaian manual sungguhan dimulai, karena mengubahnya setelah data terkumpul membuat penilaian lama dan baru tidak sebanding.

### 2. Prompt v1 (`App\Services\Scorecard\ScorecardPrompt`)

Aturan yang diberikan ke model, beserta alasannya:

| Aturan | Alasan |
|---|---|
| Ini draf untuk manusia; dilarang memberi rekomendasi terima atau tolak | Keputusan rekrutmen tetap di tangan manusia |
| Nilai isi, bukan nama, gender, usia, asal daerah, aksen, atau gaya bahasa | Mencegah bias yang paling mudah masuk lewat transkrip |
| Jangan menurunkan skor karena salah dengar ASR; kalau terlalu rusak, katakan, jangan menebak | WER ±0,18: banyak kata rusak bukan kesalahan kandidat |
| Kutipan **disalin persis, termasuk salah dengarnya**, beserta `answer_id` | Temuan ADR-008: LLM cenderung "membetulkan" ASR, sehingga kutipannya tidak lagi bisa dicek |
| Tanpa bukti → `has_evidence: false`, tanpa angka | Kriteria tanpa jawaban lebih jujur dibiarkan kosong daripada diberi angka tebakan |
| Kriteria boleh dinilai dari jawaban mana pun | Sama dengan asumsi retrieval di ADR-008 |

Format jawaban memakai JSON Schema (structured output) dengan fitur yang didukung semua penyedia. Itu sebabnya dipakai `has_evidence` + `score`, bukan integer yang boleh null (union type tidak didukung merata). `VERSION = 'v1'` disimpan di setiap hasil. Setiap perubahan pada kata-kata, aturan, atau skema menaikkan versi ini.

### 3. Pembuat kartu skor (`ScorecardGenerator`) tidak mempercayai jawaban model

- Kriteria yang tidak dikenal atau ganda dibuang. Kriteria yang tidak dinilai model tetap ditambahkan, tanpa skor dan dengan keterangan "Model tidak menilai kriteria ini".
- Skor di luar 1–5 dianggap "tidak ada bukti".
- Setiap kutipan diverifikasi (ADR-008). Kalau kutipan tidak ditemukan di jawaban yang disebut model, semua jawaban lain diperiksa, dan kutipan dipindahkan ke jawaban yang paling cocok, karena model sering salah menyebut `answer_id`.
- Semua panggilan jaringan (LLM, embedding) selesai sebelum transaksi database dimulai, sehingga kegagalan tidak pernah meninggalkan kartu skor setengah jadi. Kalau dibuat ulang, draf lama diganti.
- Yang disimpan: model, penyedia, versi prompt, kondisi, token, biaya (OpenRouter), latensi, statistik verifikasi, potongan hasil retrieval, dan JSON mentah.
- Latensi yang tercatat **termasuk waktu tunggu percobaan ulang** ketika penyedia membalas 503.

### 4. Pengaman

1. Tidak ada transkrip → ditolak.
2. Penyedia `LLM_TEST_DATA_ONLY=true` (Gemini gratis) → hanya sesi `is_test_data` yang boleh diproses. Tes memastikan **tidak ada satu pun permintaan HTTP keluar** untuk sesi peserta.
3. Sesi peserta → wajib ada persetujuan v2 (ADR-009).
4. **Draf AI disembunyikan dari penilai yang belum mengirim penilaian manualnya**, dengan aturan yang sama seperti penilaian antarpanel. Admin dan sesi data uji dikecualikan.

Kolom baru `interview_sessions.is_test_data` menandai naskah bacaan dan wawancara rekaan. Sesi bertanda ini tidak masuk angka evaluasi.

### 5. Cara menjalankan

- Tombol **"Buat kartu skor"** di halaman sesi → job antrean `GenerateScorecard`: 3 percobaan dengan jeda 30 dan 120 detik. Kegagalan terakhir ditampilkan di halaman, dan status sesi dikembalikan. Butuh `php artisan queue:work`.
- `php artisan hirenote:scorecard {id} [--condition=retrieval]` menjalankan alur yang sama langsung di terminal dan mencetak hasilnya. Ini juga pintu masuk untuk eksperimen.
- `php artisan hirenote:demo-session` membuat sesi uji yang sudah selesai dari keluaran ASR asli naskah bacaan (bukan teks karangan). Ia bisa dijalankan ulang.

## Bukti (2026-10-07, data uji, bukan evaluasi)

Detail lengkapnya ada di `docs/experiments/raw/nlp/2026-10-07_scorecard-demo-runs.md`. Dari 4 run dengan `gemini-3.5-flash`:

- 19 dari 19 kutipan `exact`: aturan menyalin persis dipatuhi. Skor stabil di tiga run kondisi penuh (5 / 4 / tidak ada bukti / 3).
- **Batas verifikasi yang terlihat jelas:** jawaban code review yang rusak parah ("refiur mulia etxkesi") tetap ditafsirkan model, dengan bantuan teks pertanyaan, lalu diberi skor 3. Kutipannya `exact`, jadi tidak tertangkap verifikasi. Verifikasi menjamin kata-katanya ada, bukan bahwa maknanya terbaca. Pada kondisi retrieval, kalimat yang sama muncul tanpa pertanyaannya, dan model menyatakan tidak ada bukti.

## Konsekuensi

- Halaman tinjau, sunting, dan setujui untuk HRD adalah langkah berikutnya. Tampilan kartu skor yang ada sekarang masih hanya-baca.
- Kandidat perbaikan prompt v2 (setelah ada data nyata, bukan sekarang): minta model menandai kriteria yang buktinya hanya dari bagian transkrip yang rusak; beri ambang relevansi minimum pada retrieval.
- Model tetap memakai teks pertanyaan untuk menafsirkan ASR yang rusak. Apakah ini membantu atau berbahaya harus diukur terhadap penilaian manusia; tidak bisa diputuskan dari satu sesi bacaan.
