# Alur request: "Kirim penilaian manual"

Satu fitur ditelusuri dari klik sampai respons, sebagai contoh cara semua fitur di HireNote bekerja.

## Skenario

Pewawancara membuka halaman sesi, memberi skor 1–5 pada tiap kriteria, lalu menekan **Kirim penilaian**.

## Langkah demi langkah

```
[1] Browser                  POST /interview-sessions/7/manual-assessment
    form: _token, scores[3]=4, scores[4]=2, notes[3]=..., overall_notes=..., submit=1
        │
[2] public/index.php  →  bootstrap/app.php
        │  middleware global: sesi, CSRF (VerifyCsrfToken memeriksa _token)
        │
[3] routes/web.php
        │  Route::middleware('auth') → user harus login (Authenticate)
        │  cocok: Route::post('interview-sessions/{interviewSession}/manual-assessment',
        │         [ManualAssessmentController::class, 'save'])
        │
[4] Route-model binding
        │  {interviewSession} = 7  →  InterviewSession::findOrFail(7)   (404 jika tidak ada)
        │
[5] ManualAssessmentController@save
        │  $this->authorize('view', $interviewSession)
        │     → InterviewSessionPolicy::view(): admin lolos lewat before();
        │       selain itu harus pewawancara utama / anggota panel / pemilik lowongan  (403 jika tidak)
        │
        │  $request->validate([...])  → skor integer 1–5, catatan ≤1000 karakter
        │     gagal → redirect back()->withErrors() → Blade menampilkan @error
        │
        │  cek semua kriteria lowongan punya skor (bukan sebagian)
        │
        │  ManualAssessment::firstOrCreate([session, rater = user login])
        │     sudah submitted? → back() dengan pesan "terkunci", tidak ada tulisan
        │
        │  foreach kriteria: $assessment->scores()->updateOrCreate([criterion_id], [score, notes])
        │  submit=1 → submitted_at = now();  save()
        │
[6] Redirect  →  GET /interview-sessions/7  dengan flash session('status')
        │
[7] InterviewSessionController@show
        │  eager load: candidate.jobOpening.criteria.questions, panel, manualAssessments.scores, ...
        │  $mine   = penilaian user ini
        │  $others = penilaian lain yang sudah submitted — hanya jika $mine submitted atau user admin
        │
[8] resources/views/interview-sessions/show.blade.php
        │  <x-page> → <x-app-layout> → layouts/app.blade.php (nav, @vite, flash)
        │  form penilaian di-render <fieldset disabled> karena terkunci
        │  tabel "Penilaian panel lain" tampil karena $others tidak kosong
        ▼
[9] HTML dikirim ke browser
```

## Yang perlu diperhatikan

- **Validasi di tiga lapis**: HTML (`required`, `min/max` pada radio), server (`$request->validate`), dan aturan bisnis di controller (semua kriteria harus terisi; penilaian terkunci setelah dikirim). Yang menentukan selalu lapis server.
- **Otorisasi terpisah dari autentikasi**: middleware `auth` memastikan ada user; policy memastikan user *ini* boleh menyentuh sesi *itu*.
- **Blind rating** adalah aturan tampilan di controller `show`, bukan di database: data penilai lain tetap ada, hanya tidak dikirim ke view sebelum user mengirim penilaiannya sendiri.
- **Idempoten**: `firstOrCreate` + `updateOrCreate` membuat pengiriman ulang draf tidak menggandakan baris (dijamin juga oleh unique index `(interview_session_id, rater_id)` dan `(manual_assessment_id, criterion_id)`).

Tes yang mencakup alur ini: `tests/Feature/Phase1CrudTest.php::test_manual_scoring_is_blind_until_submitted`.
