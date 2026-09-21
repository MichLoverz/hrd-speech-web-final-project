# Daftar route

Dihasilkan dari `php artisan route:list --except-vendor` pada 2026-09-21 (akhir Fase 1). Semua route aplikasi berada di balik middleware `auth`; kolom *Akses* menunjukkan pembatasan tambahan (middleware `role:*` atau policy di dalam controller).

## Lowongan, kriteria, pertanyaan

| Method | URI | Nama | Controller | Akses | Fungsi |
|---|---|---|---|---|---|
| GET | `/job-openings` | `job-openings.index` | `JobOpeningController@index` | semua peran | Daftar lowongan + cari/filter status/pagination. HRD hanya lihat miliknya |
| GET | `/job-openings/create` | `job-openings.create` | `@create` | HRD, admin | Form lowongan baru |
| POST | `/job-openings` | `job-openings.store` | `@store` | HRD, admin | Simpan lowongan (validasi `StoreJobOpeningRequest`) |
| GET | `/job-openings/{id}` | `job-openings.show` | `@show` | policy `view` | Detail + editor kriteria/pertanyaan inline + daftar kandidat |
| GET | `/job-openings/{id}/edit` | `job-openings.edit` | `@edit` | policy `update` | Form ubah |
| PUT | `/job-openings/{id}` | `job-openings.update` | `@update` | policy `update` | Simpan perubahan |
| DELETE | `/job-openings/{id}` | `job-openings.destroy` | `@destroy` | policy `delete` | Hapus (cascade ke kriteria, pertanyaan, kandidat, sesi) |
| GET | `/job-openings/{id}/compare` | `job-openings.compare` | `CandidateComparisonController` | policy `view` | Tabel perbandingan kandidat: rata-rata skor manual per kriteria, total berbobot, posisi gaji |
| POST | `/job-openings/{id}/criteria` | `criteria.store` | `CriterionController@store` | `role:hrd` + policy | Tambah kriteria |
| PUT | `/job-openings/{id}/criteria/{cid}` | `criteria.update` | `@update` | idem | Ubah kriteria |
| DELETE | `/job-openings/{id}/criteria/{cid}` | `criteria.destroy` | `@destroy` | idem | Hapus kriteria + pertanyaannya |
| POST | `/criteria/{cid}/questions` | `questions.store` | `QuestionController@store` | idem | Tambah pertanyaan (posisi otomatis) |
| PUT | `/criteria/{cid}/questions/{qid}` | `questions.update` | `@update` | idem | Ubah teks/urutan |
| DELETE | `/criteria/{cid}/questions/{qid}` | `questions.destroy` | `@destroy` | idem | Hapus; ditolak jika sudah punya jawaban |

## Kandidat

| Method | URI | Nama | Controller | Akses | Fungsi |
|---|---|---|---|---|---|
| GET | `/candidates` | `candidates.index` | `CandidateController@index` | semua | Daftar + cari nama/email, filter lowongan & status, pagination |
| GET | `/candidates/create` | `candidates.create` | `@create` | HRD, admin | Form kandidat (bisa pra-isi `?job_opening_id=`) |
| POST | `/candidates` | `candidates.store` | `@store` | HRD, admin | Simpan + unggah CV PDF ke disk privat |
| GET | `/candidates/{id}` | `candidates.show` | `@show` | policy `view` | Detail + daftar sesi wawancara |
| GET | `/candidates/{id}/edit` | `candidates.edit` | `@edit` | policy `update` | Form ubah |
| PUT | `/candidates/{id}` | `candidates.update` | `@update` | policy `update` | Simpan; CV lama dihapus jika diganti |
| DELETE | `/candidates/{id}` | `candidates.destroy` | `@destroy` | policy `delete` | Hapus kandidat + berkas CV |
| GET | `/candidates/{id}/cv` | `candidates.cv` | `@cv` | policy `view` | Unduh CV (satu-satunya jalan ke berkas privat) |

## Sesi wawancara dan penilaian

| Method | URI | Nama | Controller | Akses | Fungsi |
|---|---|---|---|---|---|
| GET | `/interview-sessions` | `interview-sessions.index` | `InterviewSessionController@index` | semua | Daftar sesi yang user pimpin / ikut panel / miliki lowongannya; filter kandidat, lowongan, status, rentang tanggal |
| GET | `/interview-sessions/create` | `interview-sessions.create` | `@create` | HRD, admin | Form sesi (kandidat, pewawancara utama, panel) |
| POST | `/interview-sessions` | `interview-sessions.store` | `@store` | HRD, admin | Buat sesi draf; pewawancara utama otomatis masuk pivot panel; kandidat → `interviewing` |
| GET | `/interview-sessions/{id}` | `interview-sessions.show` | `@show` | policy `view` | Detail: panel, persetujuan, pertanyaan/jawaban, form penilaian saya, penilaian panel lain (setelah kirim) |
| DELETE | `/interview-sessions/{id}` | `interview-sessions.destroy` | `@destroy` | policy `delete` | Hapus, hanya status `draft` |
| POST | `/interview-sessions/{id}/manual-assessment` | `manual-assessments.save` | `ManualAssessmentController@save` | policy `view` sesi | Simpan draf / kirim penilaian manual (skor 1–5 per kriteria); terkunci setelah dikirim |

## Admin

| Method | URI | Nama | Controller | Akses | Fungsi |
|---|---|---|---|---|---|
| GET | `/admin/users` | `admin.users.index` | `Admin\UserController@index` | `role:admin` | Daftar akun + cari + filter peran |
| PATCH | `/admin/users/{id}/role` | `admin.users.role` | `@updateRole` | `role:admin` | Ubah peran; admin tidak bisa mencabut peran sendiri |

## Auth & profil (Laravel Breeze)

`/login`, `/register`, `/logout`, `/forgot-password`, `/reset-password/{token}`, `/verify-email`, `/confirm-password`, `/password` (PUT), `/profile` (GET/PATCH/DELETE). `/` dan `/dashboard` dialihkan ke `job-openings.index`.

## Aturan akses (ringkas)

| Peran | Lowongan/kriteria/pertanyaan | Kandidat | Sesi | Penilaian manual | Admin |
|---|---|---|---|---|---|
| admin | semua | semua | semua | semua | ya |
| hrd | CRUD miliknya; baca milik orang lain tidak | mengikuti lowongannya | buat untuk lowongannya; lihat yang dipimpin/ikut | pada sesi yang bisa dilihat | – |
| interviewer | baca saja | baca saja | lihat yang dipimpin/ikut | pada sesi yang bisa dilihat | – |

Implementasi: `App\Policies\{JobOpening,Candidate,InterviewSession}Policy` (metode `before()` meloloskan admin) dan middleware `App\Http\Middleware\EnsureUserHasRole` (alias `role`).
