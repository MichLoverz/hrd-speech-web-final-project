# ADR-008: Model embedding dan verifikasi kutipan lima tingkat

Tanggal: 2026-10-03 · Status: diterima (merinci ADR-004; ambang masih sementara)

## Konteks

ADR-004 menetapkan pipeline kartu skor: retrieval embedding lokal → LLM → verifikasi kutipan (tepat, lalu cosine similarity). Dua hal di sana belum diputuskan: model embedding mana, dan bagaimana verifikasi bekerja pada transkrip ASR yang nyata. Bagian ini dibangun sebelum ada API key LLM, karena keduanya tidak membutuhkan LLM.

Syarat yang tidak berubah: `services/` tidak memakai PyTorch (ADR-005), dan uji dua manusia yang masih tertunda tidak boleh menjadi tidak sah. Artinya, jalur ASR tidak boleh berubah sedikit pun.

## Keputusan

### 1. Embedding: `paraphrase-multilingual-MiniLM-L12-v2` lewat fastembed (ONNX)

- **fastembed 0.8.1** menjalankan model sentence-embedding di ONNX Runtime yang sudah ada, tanpa PyTorch. Sebelum dipasang, `pip --dry-run` memastikan paket ini **tidak mengubah versi** `onnxruntime` (1.30.0), `numpy`, `tokenizers`, maupun `huggingface_hub`, yang dipakai VAD dan diarization.
- Model bawaannya adalah konversi ONNX **terkuantisasi** buatan Qdrant (`qdrant/paraphrase-multilingual-MiniLM-L12-v2-onnx-Q`, 0,22 GB, 384 dimensi), bukan bobot asli sentence-transformers. Ini harus disebut kalau angkanya dilaporkan.
- **IndoBERT (`indobert-base-p1`) dicoret dari kandidat.** Model itu dilatih sebagai masked language model, bukan untuk kemiripan kalimat. Memakai mean-pooling-nya tanpa fine-tuning menghasilkan kemiripan yang buruk, dan model itu juga butuh PyTorch atau ekspor ONNX sendiri.
- Pembanding untuk sub-eksperimen (sudah ada di daftar `ALLOWED_MODELS`, bisa diganti lewat `NLP_EMBED_MODEL` tanpa mengubah kode): `paraphrase-multilingual-mpnet-base-v2` (1 GB) dan `multilingual-e5-large` (2,2 GB; butuh prefiks `query:`/`passage:`, yang ditambahkan oleh service sendiri karena fastembed tidak melakukannya).
- **Model baru dimuat saat permintaan pertama, bukan saat startup.** Setelah perubahan ini, startup ASR tetap 4,8 detik dan model embedding tidak ikut dimuat. Muat pertama MiniLM ±16 detik; setelah itu 200 kalimat selesai dalam 0,62 detik di CPU.
- Kontraknya sengaja dibuat sempit. Service hanya menyediakan `POST /nlp/embed` (teks → vektor ternormalisasi). Retrieval dan verifikasi berjalan di Laravel, sehingga tingkat 1–3 verifikasi tetap jalan walaupun service mati.

### 2. Verifikasi kutipan: lima tingkat, bukan tiga

| Tingkat | Aturan | Terverifikasi? |
|---|---|---|
| `exact` | substring persis | ya |
| `normalized` | urutan kata sama, abaikan huruf besar, tanda baca, dan kata pengisi (*eee*, *hmm*) | ya |
| `fuzzy` | rangkaian kata sepanjang kutipan dengan kemiripan huruf (Levenshtein) ≥ 0,85, **dan angkanya harus sama persis** | tidak, HRD perlu melihat |
| `similar` | cosine ke jendela kata transkrip ≥ 0,80 | tidak, HRD perlu melihat |
| `none` | selain itu | tidak, dianggap karangan |

Tambahan aturan:

- Kutipan yang berisi "..." dicocokkan per potongan **sesuai urutan**. Potongan yang dibalik urutannya bukan lagi kutipan.
- Kutipan diverifikasi terhadap teks yang diberikan ke LLM (`transcript_edited ?? transcript_raw`). Posisi audio (`start_ms`/`end_ms`) hanya diisi kalau transkrip belum disunting dan segmennya menyusun kata yang sama persis. Lebih baik tanpa timestamp daripada timestamp yang salah.
- Kalau service mati saat tingkat `similar` dibutuhkan, verifikasi **melempar exception** dan tidak menyimpan `none` yang keliru. Job antrean nanti tinggal mencoba ulang.
- Kolom baru di `evidence_quotes`: `match_method`, `match_score`, `matched_text`. Dengan hanya `is_verified`, sistem tidak bisa membedakan "LLM memparafrase" dari "LLM mengarang", padahal metrik *verified-quote rate* butuh perbedaan itu.

**Kenapa tingkat `fuzzy` ditambahkan.** Uji dengan model embedding sungguhan pada transkrip `small` uji kedua (bagian Bukti) menunjukkan masalah yang tidak tertangkap tes dengan embedding palsu. LLM cenderung diam-diam membetulkan salah dengar ASR ("menabakan" → "menambahkan"). Dengan rancangan tiga tingkat, kutipan semacam ini ditolak sebagai karangan, padahal kandidat memang mengatakannya. Dengan WER ±0,18, kasus ini akan sering muncul. Kemiripan huruf menangkapnya tanpa memanggil service.

**Kenapa angka dikecualikan dari `fuzzy`.** Dalam kutipan panjang, "delapan juta" → "80 juta" hanya mengubah sedikit huruf. Pada kalimat uji, kemiripannya **0,93**, jauh di atas ambang. Padahal angka adalah isi bukti itu sendiri (gaji, lama pengalaman, ukuran tim). Jadi angka dan kata bilangan di kutipan harus sama persis dengan transkrip; kalau berbeda, kutipan turun ke tingkat `similar` atau `none`.

**Kenapa jendela kata, bukan kalimat.** Whisper jarang memberi titik, sehingga "kalimat" bisa sampai 40 kata, sementara kutipan biasanya 8–20 kata. Membandingkan kutipan pendek dengan potongan panjang menurunkan cosine-nya. Jendela 8/16/24 kata dengan pergeseran 4 kata menaikkan skor parafrase asli dari 0,594 menjadi 0,761 pada uji yang sama. Vektor jendela di-cache per jawaban, karena satu kartu skor memeriksa banyak kutipan pada jawaban yang sama.

### 3. Retrieval

Untuk tiap kriteria, query-nya adalah "nama. deskripsi", lalu sistem mengambil top-k kalimat (default 5) dari **semua** jawaban di sesi, bukan hanya jawaban atas pertanyaan milik kriteria itu. Alasannya, kandidat sering menunjukkan satu kemampuan saat menjawab pertanyaan lain. Kalimat dengan kurang dari 3 kata ("Ya.", "Oke, siap.") dibuang. Apakah retrieval lebih baik daripada mengirim transkrip penuh adalah kondisi A vs B di ADR-004, jadi itu diukur, bukan diasumsikan.

## Bukti (2026-10-03): uji kewajaran, BUKAN evaluasi

Transkrip: keluaran `small`+prompt uji kedua (ASR nyata). Ada **enam kutipan uji yang ditulis tangan**. Ini sekadar memeriksa bahwa logikanya bekerja dengan embedding sungguhan; enam contoh tidak cukup untuk mengkalibrasi ambang dan tidak boleh dilaporkan sebagai hasil. Log lengkap ada di `docs/experiments/raw/nlp/2026-10-03_quote-verifier-sanity.md`.

| Kutipan uji | Hasil | Skor |
|---|---|---|
| verbatim dari transkrip | exact | – |
| sama, tapi LLM membetulkan "menabakan" | fuzzy | 0,962 |
| beda huruf besar dan koma | normalized | – |
| parafrase sungguhan (eager loading / N+1) | none | 0,761 |
| karangan, masih sejalan topik (replika baca) | none | 0,646 |
| karangan, tidak berhubungan | none | 0,178 |

Yang terbaca dari sini: dengan ambang sementara 0,80, parafrase sungguhan **ikut tertolak**, dan selisihnya dengan karangan yang sejalan topik hanya 0,12. Ambang 0,80 itu berhati-hati (karangan tidak lolos, tapi sebagian parafrase juga tidak). Angka finalnya harus ditentukan dari kutipan LLM yang nyata dan dinilai manusia, setelah ada wawancara sungguhan.

## Konsekuensi

- `NLP_QUOTE_SIMILARITY_THRESHOLD=0.80` dan `NLP_QUOTE_FUZZY_THRESHOLD=0.85` adalah **nilai sementara**. Kalibrasinya adalah satu-satunya "tuning" dari data di pipeline ini (ADR-004) dan ditulis apa adanya.
- Halaman tinjau (Fase 4) harus menampilkan `matched_text` di samping kutipan untuk `fuzzy` dan `similar`, supaya HRD bisa melihat apa yang sebenarnya ditranskripsi.
- Metrik *verified-quote rate* dilaporkan per tingkat, bukan satu angka. Rasio `fuzzy` sendiri adalah jembatan SR → NLP: makin tinggi WER, makin sering LLM "membetulkan" ASR.
- Migrasi `2026_10_03_000100` perlu dijalankan di database dev (`php artisan migrate`).
