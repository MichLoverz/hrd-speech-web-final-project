# Kandidat dataset — fine-tune Whisper `small` (Deep Learning)

Dicari 2026-09-25. Syarat dari dosen: dataset terpercaya yang **diterbitkan lewat paper ber-DOI** (datasetnya sendiri tidak harus punya DOI). Setiap DOI di bawah dicek langsung ke Crossref atau halaman penerbit, bukan dari ingatan.

Urutan pencarian: (1) suara campur kode Indonesia–Inggris, (2) korpus Indonesia, (3) korpus Inggris.

## 1. Campur kode Indonesia–Inggris

| Dataset | Paper & DOI | Isi | Status |
|---|---|---|---|
| **Hartanto CS** | Roosadi & Lestari, *Handling of Indonesian-English Codeswitching Speech in End-to-End Indonesian Speech Recognition System Using CTC Model*, ICEEI 2023 — **10.1109/ICEEI59426.2023.10346710** ✓. Juga dipakai Tazakka dkk., LREC-COLING 2024 (SIGUL) — **10.63317/3hriruv4bj9u** ✓ | Suara **asli**, 7,15 jam (6,0 latih / 1,15 uji), ±3.399 ujaran. Bicara **spontan** bernada formal, ada kata pengisi dan bising latar | ⚠️ **Paling cocok, tapi tidak bisa diunduh.** Asalnya skripsi S1 ITB (Hartanto, 2019). Satu ulasan menggolongkannya "tersedia publik", tapi tautan unduhannya tidak ditemukan. Jalan satu-satunya: meminta ke penulis (Dessi Puji Lestari, ITB) |
| CS-FLEURS | Yan dkk., Interspeech 2025 — **10.21437/Interspeech.2025-2247** ✓ | Pasangan Indonesia–Inggris hanya ada di subset MMS: **TTS sambung-potong satu pembicara**, "mengandung artefak tidak alami", tidak divalidasi manusia, **khusus uji** (tidak ada data latih) | ❌ Tidak untuk dilatih. Juga dibangun dari kalimat FLEURS `test`, yang sudah kita pakai sebagai alat uji |
| `shulhaaja/id-en-codeswitch-dataset-alternative` (Hugging Face) | Tidak ada paper — proyek akhir mahasiswa | Suara sintetis TTS | ❌ Tidak ada paper ber-DOI, dan sintetis |
| `iqzaardiansyah/en-id-code-switch-dataset` (Hugging Face) | Tidak ada paper, tidak ada README | Folder `synthetic_audio_output` — sintetis | ❌ Sama |

**Kesimpulan:** tidak ada korpus suara campur kode Indonesia–Inggris yang **asli, publik, dan ber-paper DOI** yang bisa langsung diunduh. Satu-satunya yang asli (Hartanto CS) harus diminta.

## 2. Korpus bahasa Indonesia (cadangan)

| Dataset | Paper & DOI | Isi | Status |
|---|---|---|---|
| **FLEURS `id_id` split `train`** | Conneau dkk., *FLEURS: Few-shot Learning Evaluation of Universal Representations of Speech*, IEEE SLT 2022 — **10.1109/SLT54892.2023.10023141** ✓ | 9,1 jam, 2.579 ujaran, 1.498 perempuan / 1.081 laki-laki. Bacaan kalimat Wikipedia. CC-BY-4.0 | ✅ **Lolos.** Diperiksa: **tidak ada satu kalimat pun yang sama dengan split `test`** (alat uji kita). Berkas transkripnya sudah terunduh; audionya 1,7 GB |
| Common Voice Indonesian (v25.0, Maret 2026) | Ardila dkk., *Common Voice: A Massively-Multilingual Speech Corpus*, LREC 2020. Versi terbit LREC **tidak punya DOI**; versi arXiv punya **10.48550/arXiv.1912.06670** ✓ | 67 jam, 33,6 jam tervalidasi, 666 pembicara. CC-0 | ⚠️ **Tergantung dosen.** DOI-nya milik versi pracetak arXiv, bukan versi yang ditelaah sejawat. Sejak Oktober 2025 hanya bisa diunduh lewat Mozilla Data Collective |

## 3. Korpus bahasa Inggris (untuk mencegah model "lupa")

| Dataset | Paper & DOI | Isi | Status |
|---|---|---|---|
| **FLEURS `en_us`** | Paper dan DOI sama dengan FLEURS di atas ✓ | Bacaan kalimat yang sama sumbernya, versi Inggris | ✅ Lolos. Ukurannya belum dicek |
| LibriSpeech | Panayotov dkk., ICASSP 2015 — **10.1109/ICASSP.2015.7178964** ✓ | Buku audio Inggris, ±960 jam — cukup ambil sebagian kecil | ✅ Lolos. Cadangan kalau FLEURS `en_us` kurang |

## Rekomendasi

1. **Pasangan utama: FLEURS `id_id` train + FLEURS `en_us`.** Satu paper, satu DOI terverifikasi, format sama, alat unduh dan penilaiannya sudah ada di `tools/fleurs_eval.py`, dan pemisahan dengan data uji sudah dibuktikan bersih.
2. **Sambil jalan: minta Hartanto CS ke penulisnya.** Kalau diberi, ini dataset paling berharga — satu-satunya yang berisi campur kode **spontan dan berbising**, paling mirip wawancara sungguhan. Kalau tidak dibalas, rencana nomor 1 tetap jalan.
3. **Common Voice** hanya kalau dosen menerima DOI arXiv — untuk menambah volume dan ragam pembicara.

## Peringatan untuk desain eksperimen

- FLEURS `train` dan FLEURS `test` sama-sama bacaan Wikipedia. Model yang dilatih di `train` **hampir pasti membaik di `test`** karena domainnya sama — itu belum membuktikan membaik di wawancara. Ukuran yang menentukan tetap **rekaman wawancaramu sendiri**.
- 9,1 jam tergolong kecil untuk fine-tune. Wajar untuk bahasa yang datanya sedikit, tapi perlu pengamanan terhadap *overfitting* (validasi di split `dev`, berhenti lebih awal). Augmentasi data dibahas terpisah, setelah datasetnya pasti.
- Rekaman wawancaramu dan FLEURS `test` **tidak boleh ikut dilatih**.
