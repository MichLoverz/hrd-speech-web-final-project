# ADR-009: Penyedia LLM dan persetujuan kandidat versi 2

Tanggal: 2026-10-03 · Status: diterima

## Konteks

ADR-004 merencanakan `SummarizerInterface` dengan adapter OpenRouter dan Ollama. Sebelum adapter itu ditulis, ada tiga hal yang muncul:

1. Mahasiswa belum punya saldo OpenRouter dan ingin memakai **Gemini API tier gratis** selama pengembangan.
2. Ketentuan Gemini API (ai.google.dev/gemini-api/terms, dibaca 2026-10-03) untuk tier gratis menyatakan: *"Google uses the content you submit ... to provide, improve, and develop Google products"*, *"Human reviewers may read, annotate, and process your API input and output"*, dan *"Do not submit sensitive, confidential, or personal information to the Unpaid Services."* Tier berbayar tidak memakai data untuk pengembangan produk.
3. Teks persetujuan kandidat v1 hanya berjanji bahwa **audio** tidak dikirim ke pihak ketiga. Pengiriman **teks transkrip** ke LLM eksternal tidak disebut sama sekali.

## Keputusan

### 1. Satu client yang kompatibel dengan format OpenAI untuk semua penyedia

`App\Services\Llm\ChatClient` memanggil `/chat/completions`. Gemini (`generativelanguage.googleapis.com/v1beta/openai`), OpenRouter (`openrouter.ai/api/v1`), dan Ollama memakai format yang sama, jadi berganti penyedia cukup dengan mengubah `.env`. Satu adapter per penyedia, seperti rencana ADR-004, tidak diperlukan.

Cabang kode khusus penyedia hanya ada untuk OpenRouter:
- `provider.data_collection = deny`, supaya permintaan tidak pernah diteruskan ke penyedia yang menyimpan atau melatih model dengan prompt.
- `usage.include`, supaya biaya yang ditagih ikut dikembalikan.
- Parameter reasoning memakai `reasoning.effort` milik OpenRouter. Penyedia lain memakai `reasoning_effort` milik OpenAI.

Setiap jawaban mencatat token masuk, token keluar, token reasoning, biaya (khusus OpenRouter), dan latensi. Catatan ini menjadi angka "biaya per wawancara" yang terukur, bukan perkiraan.

### 2. Gemini gratis hanya untuk data uji

| Tahap | Penyedia | `LLM_TEST_DATA_ONLY` |
|---|---|---|
| Pengembangan (naskah bacaan, wawancara rekaan tanpa data asli) | Gemini tier gratis, `gemini-3.5-flash` | `true` |
| Eksperimen, user testing, wawancara dengan peserta | OpenRouter berbayar | `false` |

`test_data_only` dibaca oleh tombol "buat kartu skor" (dibangun berikutnya), yang menampilkan peringatan tegas selama flag itu aktif. Untuk model Gemini 3, reasoning tidak bisa dimatikan menurut dokumentasi Google, jadi defaultnya `low`.

`php artisan llm:ping` memeriksa key dengan satu permintaan kecil tanpa data pribadi, sehingga key tidak perlu diperlihatkan ke siapa pun, termasuk ke asisten AI.

### 3. Persetujuan v2

Kalimat baru dalam teks persetujuan:

> Untuk menyusun draf kartu skor, teks transkrip jawaban Anda dikirim ke layanan AI eksternal. Layanan yang dipakai tidak menggunakan data ini untuk melatih modelnya. Kartu skor hanya draf: penilaian akhir tetap dibuat dan disetujui oleh pewawancara.

- `Consent::CURRENT_TEXT_VERSION = 'v2'`, `EXTERNAL_AI_VERSIONS = ['v2']`, `InterviewSession::allowsExternalAi()`.
- Sesi dengan persetujuan v1 **tetap boleh direkam**, karena v1 memang mencakup perekaman. Tetapi sesi itu tidak boleh dikirim ke LLM sebelum kandidat menyetujui v2. Halaman persetujuan menampilkan teks v2 dan peringatan "teks lama". Persetujuan ulang **menambah** baris baru (baris v1 tidak dihapus) dan tidak mengubah status, mode, maupun waktu mulai sesi yang sudah direkam.
- Kalimat "tidak menggunakan data ini untuk melatih modelnya" hanya benar untuk penyedia berbayar dengan `data_collection = deny`. Inilah alasan teknis sekaligus etis kenapa Gemini gratis dibatasi untuk data uji.

## Konsekuensi

- Saldo OpenRouter (rencananya top-up $10) baru dibutuhkan menjelang eksperimen, bukan sekarang.
- Prompt yang disetel di Gemini Flash perlu dicek ulang dengan model eksperimen sebelum eksperimen dijalankan.
- Uji dua manusia yang tertunda akan memakai teks persetujuan v2. Jalur rekam, transkripsi, dan diarization tidak berubah.
