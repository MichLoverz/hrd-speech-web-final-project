# PROMPT UNTUK SESI VS CODE — Proyek HireNote

> **Cara pakai:** tempel seluruh isi berkas ini sebagai pesan pertama di sesi coding baru (Claude Code / Copilot Chat / apa pun yang kamu pakai di VS Code). Setelah itu, minta asisten mengonfirmasi rencananya dulu sebelum menulis kode.

---

## 0. PERAN DAN ATURAN DASAR

Kamu adalah asisten pengembangan untuk proyek kuliah saya. Proyek ini dipakai untuk **tiga mata kuliah sekaligus**, jadi setiap keputusan teknis harus memenuhi ketiganya. Sebelum menulis kode apa pun, baca seluruh dokumen ini, lalu ajukan rencana kerja untuk saya setujui.

**Aturan yang tidak boleh dilanggar:**

1. **Jangan pernah menambahkan trailer co-author** (`Co-Authored-By`, `Claude-Session`, atau sejenisnya) pada pesan commit git. Berlaku permanen.
2. Jangan menjelaskan panjang lebar konsep teknis dasar. Langsung ke intinya. Saya mahasiswa semester 5 yang sudah terbiasa dengan Laravel, Python, git, dan WSL.
3. Jangan membuat data, hasil eksperimen, atau hasil user testing fiktif. Ini pelanggaran integritas akademik yang berat di mata kuliah ini. Kalau butuh data, bilang saya harus mengumpulkannya sendiri.
4. Setiap keputusan teknis yang penting harus saya pahami. Kalau kamu memilih pendekatan tertentu, jelaskan alasannya dalam 2–3 kalimat supaya saya bisa mempertanggungjawabkannya saat demo.
5. Catat semua yang perlu dicatat untuk dokumentasi **selama pengerjaan**, bukan di akhir. Detailnya ada di Bagian 10.

---

## 1. KONTEKS: SATU PROYEK, TIGA MATA KULIAH

| Mata Kuliah | Yang Dinilai | Deliverable |
|---|---|---|
| **Web Programming** | Laravel, CRUD, relasi antar tabel, struktur kode, dokumentasi | Aplikasi web + dokumentasi teknis |
| **Speech Recognition** | ASR real-time dengan model lokal, eksperimen, WER, latency, user testing | Aplikasi real-time + Technical Report |
| **Venture Creation** | Kelayakan bisnis, target pengguna nyata | Pitch + validasi pengguna (sudah diterima dosen) |

Konflik prioritas diselesaikan dengan urutan: **syarat Speech Recognition menang**, karena syaratnya paling kaku dan paling mudah gagal. Web Programming fleksibel soal cara, kaku soal kelengkapan CRUD dan relasi.

---

## 2. TENTANG PRODUKNYA

**HireNote — Sistem Penilaian Wawancara Terstruktur untuk Rekrutmen**

**SDG 8 (Pekerjaan Layak dan Pertumbuhan Ekonomi).** Wawancara kerja yang tidak terdokumentasi membuat keputusan rekrutmen bergantung pada ingatan dan kesan sesaat. Dengan merekam dan mentranskripsi wawancara, lalu menilai semua kandidat terhadap kriteria yang sama, proses seleksi jadi lebih adil dan bisa ditelusuri.

**Alur produk:**

```
HRD buat lowongan + kriteria + pertanyaan
        ↓
Kandidat setujui perekaman (tercatat di sistem)
        ↓
Sesi wawancara: rekam jawaban PER PERTANYAAN
        ↓
Transkrip muncul REAL-TIME saat kandidat masih bicara
        ↓
Transkrip bisa dikoreksi manual oleh HRD
        ↓
Analisis → Kartu Skor (kelebihan, kekurangan, kecocokan gaji)
        ↓
Tiap poin disertai kutipan transkrip sebagai bukti
        ↓
HRD tinjau, sunting, setujui
        ↓
Dashboard perbandingan kandidat per lowongan
```

**Target pengguna nyata (untuk user testing):**
- Staf dan manajer HRD di perusahaan kecil–menengah
- Pengguna perekrutan di startup tanpa ATS berbayar
- Bagian kemahasiswaan / career center kampus yang menyeleksi peserta magang

---

## 3. SYARAT MATI DARI MATA KULIAH SPEECH RECOGNITION

Ini yang paling menentukan arsitektur. Baca pelan-pelan.

### 3.1 Dilarang keras

- **Tidak boleh memakai External Speech Recognition API.** Google Speech-to-Text, Azure Speech, Amazon Transcribe, OpenAI Speech API, AssemblyAI, Deepgram — semuanya dilarang.
- Inference ASR **harus berjalan lokal**.

### 3.2 Yang diperbolehkan

Pretrained ASR models, open-source ASR models, Hugging Face models, PyTorch, TensorFlow, ONNX, torchaudio, librosa, FFmpeg, dan library pendukung lainnya.

> **Catatan penting:** larangan ini khusus untuk *Speech Recognition*. LLM untuk tahap peringkasan transkrip menjadi Kartu Skor **bukan** ASR, jadi secara aturan boleh. Tapi tetap dicatat di AI Usage Log, dan saya akan konfirmasi ke dosen. Siapkan arsitektur supaya tahap peringkasan bisa diganti ke model lokal (Ollama) tanpa membongkar kode — pakai interface/adapter.

### 3.3 Definisi real-time yang dipakai dosen

Sistem harus memproses suara **selagi pengguna berbicara** dan menghasilkan transkripsi parsial/terbarui dengan latency rendah, tanpa menunggu pengguna selesai merekam.

**Alur yang DITOLAK:**
```
Speak → Stop Recording → Upload Complete Audio → Wait → Transcript
```

**Alur yang DITERIMA:**
```
User starts speaking → Audio stream captured → Continuous processing
→ Partial transcription → Updated transcription → User continues speaking
```

**Syarat operasional minimum:**
1. Menerima audio secara continuous/streaming
2. Melakukan inference selama speech berlangsung
3. Menghasilkan partial/intermediate transcription
4. Memperbarui transcription saat speech berikutnya masuk
5. Tidak membutuhkan rekaman lengkap sebelum transkripsi dimulai

Sistem juga harus bisa **mengukur dan melaporkan latency**.

### 3.4 Yang wajib ada di akhir

- Technical evaluation: baseline + minimal satu experimental condition
- WER (Word Error Rate) — minimal ini, boleh tambah CER
- Pengukuran latency / responsiveness / real-time factor
- Error analysis dengan contoh konkret dan pola kesalahannya
- User testing terhadap **minimal 5 target user nyata**
- Hasil user testing dihubungkan dengan hasil evaluasi teknis
- AI Usage Log

---

## 4. ARSITEKTUR TARGET

Dua proses terpisah yang bicara lewat WebSocket. Jangan gabungkan.

```
┌─────────────────────────────────────────────────────────┐
│ BROWSER (halaman sesi wawancara)                        │
│  • AudioWorklet menangkap PCM 16 kHz mono               │
│  • Kirim chunk audio via WebSocket ke ASR service       │
│  • Terima partial + final transcript, render live       │
│  • Tampilkan indikator latency dan status               │
└────────────────────┬────────────────────────────────────┘
                     │ WebSocket (audio chunk + transcript)
                     ▼
┌─────────────────────────────────────────────────────────┐
│ ASR SERVICE — Python, jalan di WSL                      │
│  • FastAPI + WebSocket endpoint                         │
│  • Ring buffer + VAD (Silero / webrtcvad)               │
│  • faster-whisper inference per chunk                   │
│  • Emit partial transcript setiap N detik               │
│  • Emit final transcript saat VAD deteksi jeda          │
│  • Catat timestamp untuk pengukuran latency             │
└────────────────────┬────────────────────────────────────┘
                     │ HTTP (final transcript + metrik)
                     ▼
┌─────────────────────────────────────────────────────────┐
│ LARAVEL — aplikasi utama                                │
│  • Auth + role (Admin / HRD / Pewawancara)              │
│  • CRUD lowongan, kriteria, pertanyaan, kandidat        │
│  • Simpan transkrip + metrik latency per segmen         │
│  • Queue job: analisis transkrip → Kartu Skor           │
│  • Review & approval, dashboard perbandingan            │
└─────────────────────────────────────────────────────────┘
```

**Kenapa dipisah:** Laravel tidak bisa menjalankan inference PyTorch, dan menjalankan ASR di dalam request HTTP akan timeout. Pemisahan ini juga poin bagus untuk bab System Design di Technical Report.

---

## 5. TECH STACK

**Laravel**
- Laravel 12 (atau 11 kalau 12 bermasalah), PHP 8.3+
- Database: PostgreSQL (fallback MySQL — saya terbiasa keduanya)
- Queue: database driver (cukup, tidak perlu Redis)
- Blade + Alpine.js + Tailwind. Jangan pakai SPA framework — menambah kerumitan tanpa nilai tambah di penilaian Web Programming
- Laravel Breeze untuk scaffolding auth

**ASR Service**
- Python 3.11+, dijalankan di WSL
- FastAPI + `uvicorn` + `websockets`
- `faster-whisper` (CTranslate2) — jauh lebih cepat dari openai-whisper di CPU
- VAD: `silero-vad` atau `webrtcvad`
- `numpy`, `soundfile`, `ffmpeg`
- `jiwer` untuk perhitungan WER

**Model ASR**
- Mulai dari `small` untuk pengembangan (cepat iterasi)
- Uji `medium` untuk hasil akhir — akurasi bahasa Indonesia jauh lebih baik
- Set `language="id"`
- Ukur kedua ukuran model dan **masukkan perbandingannya sebagai salah satu eksperimen** di Technical Report. Ini "gratis" — kamu dapat satu experimental condition tanpa kerja tambahan.

**Peringkasan (Kartu Skor)**
- Interface `SummarizerInterface` dengan dua implementasi: `OpenRouterSummarizer` dan `OllamaSummarizer`
- Bisa ditukar lewat config. Ini melindungi saya kalau dosen ternyata melarang LLM eksternal juga

---

## 6. SKEMA DATABASE DAN RELASI

Web Programming menilai relasi antar tabel, jadi ini harus kaya tapi tidak asal banyak.

```
users
  id, name, email, password, role (admin|hrd|interviewer)
  → hasMany: interview_sessions (sebagai pewawancara)

job_openings
  id, title, department, salary_min, salary_max, status, created_by
  → belongsTo: users (creator)
  → hasMany: criteria, candidates

criteria
  id, job_opening_id, name, weight, description
  → belongsTo: job_openings
  → hasMany: questions, scores

questions
  id, criterion_id, text, order
  → belongsTo: criteria
  → hasMany: answers

candidates
  id, job_opening_id, name, email, phone, cv_path, expected_salary, status
  → belongsTo: job_openings
  → hasMany: interview_sessions

consents
  id, candidate_id, interview_session_id, agreed_at, ip_address, consent_text_version
  → belongsTo: candidates, interview_sessions

interview_sessions
  id, candidate_id, interviewer_id (users), started_at, ended_at, status
  status: draft | recording | transcribing | analyzing | ready_for_review | approved
  → belongsTo: candidates, users
  → hasMany: answers, consents
  → hasOne: analysis_result

answers
  id, interview_session_id, question_id, audio_path, transcript_raw,
  transcript_edited, duration_ms, avg_latency_ms, model_used
  → belongsTo: interview_sessions, questions
  → hasMany: transcript_segments

transcript_segments
  id, answer_id, text, start_ms, end_ms, is_final,
  emitted_at, latency_ms, confidence
  → belongsTo: answers
  (ini sumber data untuk analisis latency di Technical Report)

analysis_results
  id, interview_session_id, raw_json, status (draft|edited|approved),
  approved_by, approved_at, model_used, prompt_version
  → belongsTo: interview_sessions
  → hasMany: scores, evidence_quotes

scores
  id, analysis_result_id, criterion_id, score, strengths, weaknesses, edited_by_human
  → belongsTo: analysis_results, criteria

evidence_quotes
  id, score_id, answer_id, quote_text, start_ms, end_ms
  → belongsTo: scores, answers
  (ini yang bikin tiap poin penilaian punya bukti)
```

**Relasi yang terwakili:** one-to-many (banyak), many-to-one, one-to-one (`interview_sessions` → `analysis_results`), dan relasi bersarang tiga tingkat (`job_openings` → `criteria` → `questions` → `answers`). Kalau perlu many-to-many eksplisit untuk Web Programming, tambahkan tabel pivot `interview_session_interviewers` untuk sesi dengan lebih dari satu pewawancara.

Buat **seeder lengkap** dengan data contoh realistis sejak awal. Saya butuh ini untuk demo dan untuk screenshot dokumentasi.

---

## 7. SPESIFIKASI ASR REAL-TIME (BAGIAN PALING KRITIS)

Ini yang paling menentukan nilai Speech Recognition. Kerjakan dengan teliti.

### 7.1 Sisi browser

- Pakai `AudioWorklet`, **bukan** `MediaRecorder`. MediaRecorder menghasilkan berkas terkompresi, bukan stream PCM mentah — tidak cocok untuk streaming inference
- Resample ke 16 kHz mono, Float32 → Int16
- Kirim chunk setiap 100–250 ms lewat WebSocket
- Simultan: kumpulkan audio lengkap untuk disimpan sebagai berkas bukti (WAV/Opus), tapi **penyimpanan ini tidak boleh memblokir jalur transkripsi**
- Tampilkan di UI: teks parsial yang terus diperbarui, indikator status (Listening / Processing), dan angka latency terakhir

### 7.2 Sisi ASR service

Strategi buffering yang saya mau:

- Ring buffer menampung audio masuk
- VAD menandai segmen bicara dan jeda
- **Partial transcript:** setiap 1–1,5 detik, transkripsikan jendela geser (sliding window) dari audio yang sudah masuk, emit sebagai `is_final=false`
- **Final transcript:** saat VAD mendeteksi jeda > 500 ms, transkripsikan segmen penuh, emit sebagai `is_final=true`, lalu kosongkan buffer segmen
- Teks final menggantikan teks parsial di UI

Kalau strategi ini terlalu berat di CPU saya, turunkan frekuensi partial atau perkecil ukuran model — **jangan** ubah jadi alur unggah-setelah-selesai. Itu langsung gagal syarat real-time.

### 7.3 Instrumentasi latency (WAJIB, jangan ditunda)

Bangun ini dari awal, bukan ditambal di akhir. Untuk tiap emit, catat:

- `chunk_received_at` — waktu chunk audio terakhir dalam jendela diterima
- `inference_started_at`
- `inference_ended_at`
- `emitted_at`
- `latency_ms` = `emitted_at` − `chunk_received_at`
- `audio_duration_ms` jendela yang diproses
- `real_time_factor` = waktu inference ÷ durasi audio

Simpan semuanya ke `transcript_segments`. Nanti tinggal query untuk dapat rata-rata, median, p95 — dan itu langsung jadi isi bab evaluasi.

### 7.4 Penanganan kegagalan

- ASR service mati → UI menampilkan status jelas, audio tetap direkam lokal, sediakan tombol transkripsi ulang
- WebSocket putus → auto-reconnect, lanjutkan dari buffer
- Selalu simpan audio mentah. Kalau transkrip hilang, rekamannya masih ada

---

## 8. SPESIFIKASI ANALISIS KARTU SKOR

- Jalankan sebagai **Laravel Queue Job**, jangan di dalam request
- Input: seluruh transkrip satu sesi, sudah terkelompok per kriteria (otomatis, karena tiap pertanyaan sudah terhubung ke kriteria)
- Output: **JSON terstruktur**, bukan prosa bebas

Skema output yang saya mau:

```json
{
  "criteria": [
    {
      "criterion_id": 1,
      "score": 4,
      "strengths": ["..."],
      "weaknesses": ["..."],
      "evidence": [
        {"answer_id": 12, "quote": "kutipan persis dari transkrip", "supports": "strength"}
      ]
    }
  ],
  "salary_fit": {
    "expected": 8000000,
    "range_min": 7000000,
    "range_max": 10000000,
    "position": "within_range",
    "note": "..."
  },
  "overall_summary": "..."
}
```

**Aturan keras untuk prompt peringkasan:**
- Setiap poin kelebihan/kekurangan **wajib** menyertakan kutipan persis dari transkrip. Kalau tidak ada kutipan pendukung, poin itu tidak boleh dikeluarkan
- Sistem **tidak menilai kelayakan gaji**. Hanya membandingkan ekspektasi kandidat dengan rentang anggaran lowongan
- Validasi di sisi Laravel: pastikan tiap `quote` benar-benar ada di transkrip aslinya (substring match). Kalau tidak ada, tandai sebagai `unverified` dan jangan tampilkan sebagai bukti. Ini mitigasi halusinasi yang konkret dan bisa saya jelaskan saat demo
- Simpan `prompt_version` di database supaya hasil lama tetap bisa ditelusuri

---

## 9. RENCANA PENGERJAAN BERTAHAP

Kerjakan berurutan. Setelah tiap fase, pastikan aplikasi tetap bisa dijalankan dan didemokan.

**Fase 0 — Fondasi**
Inisialisasi repo, Laravel + Breeze, migration semua tabel, model + relasi, seeder, role middleware. Belum ada audio sama sekali.
*Selesai bila:* login sebagai HRD, buat lowongan, kriteria, pertanyaan, kandidat, semuanya lewat UI.

**Fase 1 — CRUD lengkap**
Semua CRUD rapi, validasi, search, filter, pagination, unggah CV. Form skor manual per kriteria.
*Selesai bila:* seluruh checklist Web Programming sudah terpenuhi tanpa satu baris pun kode ASR.

**Fase 2 — Prototipe ASR terpisah**
Bangun ASR service sebagai proyek Python mandiri dulu. Uji lewat skrip CLI dengan mikrofon, belum tersambung ke Laravel. Pastikan partial transcript muncul dan latency terukur.
*Selesai bila:* saya bisa bicara ke mikrofon dan melihat teks muncul progresif di terminal, dengan angka latency tercetak.

**Fase 3 — Integrasi streaming ke browser**
AudioWorklet, WebSocket, render live di halaman sesi wawancara.
*Selesai bila:* saya bicara di browser, teks muncul progresif di layar, tersimpan ke database beserta metrik latency.

**Fase 4 — Analisis dan Kartu Skor**
Queue job, prompt peringkasan, validasi kutipan, halaman tinjau dan setujui.

**Fase 5 — Dashboard dan penyempurnaan**
Perbandingan kandidat, pencarian, filter, retensi audio otomatis.

**Fase 6 — Evaluasi**
Harness eksperimen, perhitungan WER, analisis latency, persiapan user testing.

Kalau waktu habis, berhenti di Fase 4 masih menghasilkan proyek utuh untuk ketiga mata kuliah.

---

## 10. DOKUMENTASI SELAMA PENGERJAAN (JANGAN DITUNDA)

Dokumentasi difokuskan setelah website selesai, **tapi bahannya harus dikumpulkan sejak hari pertama**. Kalau menunggu akhir, saya tidak akan ingat alasan di balik keputusan teknis, dan itu justru yang paling dinilai.

Buat struktur ini di repo sejak Fase 0:

```
docs/
  devlog/
    YYYY-MM-DD.md          ← satu berkas per hari kerja
  decisions/
    ADR-001-xxx.md         ← keputusan arsitektur
  experiments/
    raw/                   ← hasil mentah, CSV
    results/               ← tabel jadi
  screenshots/
  ai-usage-log.md
  user-testing/
    questionnaire.md
    responses/
```

### 10.1 Devlog harian

Setiap sesi kerja berakhir, tulis entri berisi:
- Apa yang dikerjakan hari ini
- Masalah yang muncul dan bagaimana diselesaikan
- Keputusan teknis yang diambil beserta alasannya
- Apa yang dicoba tapi gagal, dan kenapa gagal
- Angka apa pun yang terukur (latency, waktu proses, ukuran model)

**Bagian "yang gagal" itu penting.** Technical Report butuh bab Discussion soal kelemahan sistem, dan sumber terbaiknya adalah catatan kegagalan yang jujur.

### 10.2 Architecture Decision Record

Tiap keputusan penting dapat satu berkas pendek: konteks, opsi yang dipertimbangkan, keputusan, konsekuensi.

Minimal harus ada ADR untuk:
- Kenapa faster-whisper, bukan openai-whisper atau Vosk
- Kenapa AudioWorklet, bukan MediaRecorder
- Kenapa ASR dipisah sebagai service, bukan di dalam Laravel
- Kenapa rekam per pertanyaan, bukan satu sesi panjang
- Strategi buffering dan chunking yang dipilih
- Kenapa ukuran model tertentu
- Kenapa peringkasan pakai LLM dan bagaimana halusinasi dimitigasi

### 10.3 Hal yang wajib dicatat khusus untuk Technical Report

Setiap kali menyentuh ASR, catat ke `docs/experiments/raw/`:
- Spesifikasi perangkat: CPU, RAM, ada GPU atau tidak, versi WSL
- Versi model, versi library
- Parameter: ukuran chunk, ukuran jendela, ambang VAD, beam size
- Angka latency mentah, jangan cuma rata-rata
- Contoh transkrip yang salah, beserta referensi yang benar

Yang dibutuhkan Technical Report dan harus terkumpul sepanjang jalan:
- Diagram arsitektur sistem (buat lebih awal, perbarui saat berubah)
- Diagram alur data
- Screenshot aplikasi di tiap fase
- Contoh transkripsi: benar dan salah
- Hasil WER per kondisi eksperimen
- Pengukuran latency
- Hasil user testing dan analisisnya

### 10.4 Dokumentasi untuk Web Programming

Terpisah dari Technical Report, kumpulkan sejak awal:
- ERD (buat dari migration, perbarui tiap ada perubahan skema)
- Daftar route dan fungsinya
- Penjelasan tiap model dan relasinya
- Penjelasan alur request dari klik sampai respons untuk satu fitur utama
- Panduan instalasi dan menjalankan proyek, termasuk langkah WSL untuk ASR service
- Penjelasan struktur folder

Minta saya mengisi bagian yang butuh penilaian saya sendiri. Jangan mengarang isi dokumentasi soal alasan keputusan — tanyakan ke saya.

---

## 11. AI USAGE LOG (WAJIB, DINILAI 10%)

Perbarui `docs/ai-usage-log.md` **setiap sesi kerja**, bukan di akhir. Formatnya:

| No | AI Tool | Purpose | Prompt/Instruction Summary | Output Used | Student Verification |
|---|---|---|---|---|---|
| 1 | Claude (VS Code) | Coding assistance | Minta implementasi ring buffer + VAD | Dipakai sebagian | Diuji dengan 5 rekaman berbeda, ambang VAD disesuaikan dari 0.5 ke 0.35 |

Kolom **Student Verification** yang paling dinilai. Setiap baris harus menunjukkan saya benar-benar menguji dan mengubah, bukan menyalin mentah. Jadi setiap kali kamu memberi kode yang signifikan, ingatkan saya untuk mengujinya dan catat hasil pengujiannya.

Dosen bisa meminta saya menjelaskan saat demo: cara kerja model ASR, kenapa model itu dipilih, bagaimana audio streaming bekerja, bagaimana inference real-time dilakukan, bagaimana latency diukur, bagaimana WER dihitung, dan bagaimana kode buatan AI bekerja. **Jadi jangan beri kode yang tidak kamu jelaskan.**

---

## 12. HARNESS EVALUASI

Buat skrip terpisah di `tools/`, jangan dicampur ke aplikasi.

**`tools/wer_eval.py`**
- Input: pasangan (audio, transkrip referensi manual)
- Jalankan ASR, hitung WER dan CER pakai `jiwer`
- Keluarkan CSV: per berkas, per kondisi, plus rekap
- Sertakan daftar kesalahan terklasifikasi: substitution, deletion, insertion

**`tools/latency_report.py`**
- Baca `transcript_segments` dari database
- Hitung rata-rata, median, p95 latency, dan real-time factor
- Keluarkan tabel siap tempel ke laporan

**Rancangan eksperimen** (baseline + minimal satu kondisi; ambil yang relevan dengan wawancara kerja):

| Kode | Kondisi | Kenapa relevan |
|---|---|---|
| Baseline | Ruangan sunyi, satu pembicara, bicara normal | Kondisi ideal |
| E1 | Derau latar kantor | Wawancara jarang di ruang kedap suara |
| E2 | Pembicara berbeda (logat berbeda) | Kandidat datang dari mana saja |
| E3 | Campur kode Indonesia–Inggris | Sangat khas wawancara kerja Indonesia |
| E4 | Kecepatan bicara berbeda | Kandidat gugup bicara lebih cepat |
| E5 | Ukuran model: small vs medium | Trade-off akurasi dan latency |

E3 adalah kondisi paling menarik untuk laporan, dan kemungkinan besar jadi kelemahan utama sistem. Itu justru bagus — bab Discussion butuh kelemahan yang teranalisis, bukan sistem yang pura-pura sempurna.

**Untuk data referensi:** saya akan merekam wawancara simulasi dengan teman-teman dan mentranskripsikannya manual. Bantu saya menyiapkan skrip pertanyaan dan panduan transkripsi manual yang konsisten, tapi **jangan pernah membuat transkrip atau hasil WER palsu**.

---

## 13. PRIVASI DAN PERSETUJUAN

Bukan sekadar pelengkap — ini akan ditanyakan di ketiga mata kuliah.

- Layar persetujuan sebelum sesi dimulai, tercatat dengan `agreed_at`, versi teks persetujuan, dan IP
- Kebijakan retensi: hapus otomatis berkas audio setelah N hari (konfigurasi), transkrip tetap disimpan
- Akses dibatasi per role
- Untuk user testing, siapkan formulir persetujuan terpisah
- Jangan pernah commit data wawancara nyata ke repo. Tambahkan `storage/app/recordings/` ke `.gitignore`

---

## 14. DISIPLIN GIT

- Commit kecil dan sering, satu commit per perubahan logis
- Pesan commit deskriptif dalam bahasa Inggris, format conventional commits
- **Tanpa trailer co-author, tanpa kecuali**
- Tag di akhir tiap fase: `v0.1-crud`, `v0.2-asr-prototype`, dan seterusnya
- Repo terpisah atau monorepo dengan dua folder: `web/` (Laravel) dan `asr/` (Python). Sarankan mana yang lebih baik beserta alasannya

---

## 15. YANG TIDAK BOLEH DILAKUKAN

- Jangan pakai API ASR eksternal, dalam bentuk apa pun, bahkan untuk sekadar uji coba cepat
- Jangan mengubah alur jadi unggah-setelah-selesai, meskipun lebih mudah
- Jangan membuat data, hasil eksperimen, atau feedback pengguna fiktif
- Jangan menambah fitur di luar daftar tanpa bertanya dulu
- Jangan memakai SPA framework atau menambah kompleksitas yang tidak dinilai
- Jangan menulis kode dalam potongan besar tanpa penjelasan — saya harus bisa mempertanggungjawabkannya
- Jangan menunda instrumentasi latency dan devlog sampai akhir

---

## 16. DEFINITION OF DONE

**Web Programming**
- [ ] CRUD lengkap untuk semua entitas, dengan validasi
- [ ] Relasi one-to-many, one-to-one, dan many-to-many terpakai nyata
- [ ] Auth dan role berfungsi
- [ ] Search, filter, pagination
- [ ] Unggah berkas dengan validasi
- [ ] Queue job berjalan
- [ ] Seeder lengkap
- [ ] Dokumentasi: ERD, daftar route, penjelasan relasi, panduan instalasi

**Speech Recognition**
- [ ] Transkripsi real-time streaming dengan model lokal
- [ ] Partial transcript muncul saat orang masih bicara
- [ ] Tanpa API ASR eksternal
- [ ] Latency terukur dan terlaporkan
- [ ] WER dihitung untuk baseline + minimal satu kondisi eksperimen
- [ ] Error analysis dengan contoh dan pola kesalahan
- [ ] User testing dengan minimal 5 target user nyata
- [ ] Hasil user testing dihubungkan dengan evaluasi teknis
- [ ] AI Usage Log lengkap dengan kolom verifikasi
- [ ] AI Usage Declaration di akhir laporan

**Venture Creation**
- [ ] Aplikasi bisa didemokan ke calon pengguna nyata
- [ ] Umpan balik pengguna terkumpul dan terdokumentasi

---

## 17. LANGKAH PERTAMA

Jangan langsung menulis kode. Lakukan ini dulu:

1. Konfirmasi kamu sudah membaca seluruh dokumen ini
2. Ajukan rencana Fase 0 secara rinci: struktur folder, daftar migration, daftar model beserta relasinya
3. Sebutkan asumsi yang kamu buat dan hal yang perlu saya putuskan
4. Tanyakan spesifikasi mesin saya sebelum merekomendasikan ukuran model ASR
5. Setelah saya setujui, baru mulai Fase 0

Kalau ada bagian dari dokumen ini yang menurutmu bermasalah secara teknis, katakan sekarang, jangan setelah kode ditulis.
