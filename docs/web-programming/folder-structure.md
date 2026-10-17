# Struktur folder

## Repo

```
web/        Laravel — aplikasi utama
services/   Python FastAPI — ASR real-time (/asr) + embedding NLP (/nlp)     [Fase 2, 4]
tools/      skrip evaluasi: wer_eval.py, latency_report.py, scorecard_eval.py [Fase 6]
docs/
  brief/            brief proyek (form mata kuliah .docx hanya lokal, tidak di-commit)
  decisions/        ADR-001..004
  devlog/           catatan harian
  experiments/      raw/ (CSV, log) dan results/ (tabel jadi)
  screenshots/      bukti tiap fase (hanya lokal, tidak di-commit)
  user-testing/     kuesioner + responses/
  web-programming/  erd, routes, models, request-flow, install, folder-structure (berkas ini)
  ai-usage-log.md
  design-system.md
```

## `web/` — yang ditulis untuk proyek ini

```
app/
  Http/
    Controllers/
      Admin/UserController.php          daftar akun + ubah peran
      JobOpeningController.php          resource lowongan
      CriterionController.php           tulis kriteria (inline dari halaman lowongan)
      QuestionController.php            tulis pertanyaan (inline)
      CandidateController.php           resource kandidat + unduh CV
      CandidateComparisonController.php halaman perbandingan (single-action)
      InterviewSessionController.php    daftar/buat/lihat/hapus sesi
      ManualAssessmentController.php    simpan/kirim penilaian manual
      Controller.php                    base + trait AuthorizesRequests
    Middleware/EnsureUserHasRole.php    alias `role`
    Requests/Store*Request.php          validasi + nama atribut Indonesia
  Models/                               14 model (lihat models.md)
  Policies/                             JobOpening, Candidate, InterviewSession
  Providers/AppServiceProvider.php      view pagination kustom
  Support/helpers.php                   rupiah(), status_label(), status_tone()
bootstrap/app.php                       registrasi alias middleware
database/
  migrations/                           0001_* bawaan (users + role), 2026_09_21_* skema HireNote
  factories/                            User, JobOpening, Candidate (untuk tes)
  seeders/DatabaseSeeder.php            data demo tanpa transkrip/skor
lang/id/, lang/id.json                  pesan validasi & UI Indonesia
resources/
  css/app.css                           token desain + kelas komponen (@apply)
  views/
    layouts/app, guest, navigation      kerangka halaman, @vite di sini
    components/                         page, status, field, money-input, confirm-delete, + Breeze
    job-openings/                       index, create, edit, show, compare, _form, _criterion-fields, _question-fields
    candidates/                         index, create, edit, show, _form
    interview-sessions/                 index, create, show
    admin/users/index
    vendor/pagination/hirenote          pagination
    auth/, profile/                     Breeze (diterjemahkan & di-restyle)
routes/web.php                          semua route aplikasi; auth.php dari Breeze
tailwind.config.js                      palet, font, skala tipe
tests/Feature/
  Phase0FoundationTest.php              CRUD dasar, validasi, role
  Phase1CrudTest.php                    admin, filter sesi, penilaian manual, perbandingan
```

Yang **tidak** masuk git: `vendor/`, `node_modules/`, `public/build/`, `.env`, `storage/app/private/cvs/` (CV kandidat), `storage/app/recordings/` (audio wawancara).
