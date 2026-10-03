# Model dan relasi

Semua model di `web/app/Models/`. Diagram: [erd.md](erd.md). Alasan skema: [ADR-003](../decisions/ADR-003-mysql-and-schema-choices.md).

| Model | Tabel | Relasi | Catatan |
|---|---|---|---|
| `User` | `users` | `jobOpenings()` hasMany (created_by) · `ledSessions()` hasMany (interviewer_id) · `panelSessions()` belongsToMany via `interview_session_interviewers` · `manualAssessments()` hasMany (rater_id) · `approvedAnalyses()` hasMany (approved_by) | Kolom `role` enum `admin/hrd/interviewer`; helper `isAdmin()`, `hasRole(...)` |
| `JobOpening` | `job_openings` | `creator()` belongsTo User · `criteria()` hasMany · `candidates()` hasMany · `questions()` **hasManyThrough** Criterion | Scope `visibleTo(User)` (admin semua, HRD miliknya) dan `search(term)` |
| `Criterion` | `criteria` | `jobOpening()` belongsTo · `questions()` hasMany (urut `position`) · `scores()` hasMany (AI) · `manualScores()` hasMany (manusia) | `$table = 'criteria'` karena Eloquent akan menebak `criterions` |
| `Question` | `questions` | `criterion()` belongsTo · `answers()` hasMany | `position`, bukan `order` (reserved word) |
| `Candidate` | `candidates` | `jobOpening()` belongsTo · `interviewSessions()` hasMany · `consents()` hasMany | `cv_path` di disk privat; scope `search` |
| `InterviewSession` | `interview_sessions` | `candidate()` belongsTo · `interviewer()` belongsTo User · `panel()` **belongsToMany** User · `answers()` hasMany · `consents()` hasMany · `manualAssessments()` hasMany · `analysisResult()` **hasOne** | Status enum 6 nilai; `hasConsent()` |
| `Consent` | `consents` | `candidate()`, `interviewSession()` belongsTo | Konstanta `CURRENT_TEXT_VERSION` |
| `Answer` | `answers` | `interviewSession()`, `question()` belongsTo · `transcriptSegments()` hasMany · `evidenceQuotes()` hasMany | Accessor `transcript` = edited ?? raw. Unik per (sesi, pertanyaan) |
| `TranscriptSegment` | `transcript_segments` | `answer()` belongsTo | Instrumentasi latency per emit (Fase 3) |
| `AnalysisResult` | `analysis_results` | `interviewSession()` belongsTo · `approver()` belongsTo User · `scores()` hasMany · `evidenceQuotes()` **hasManyThrough** Score | Kartu skor AI, satu per sesi (`interview_session_id` unik) |
| `Score` | `scores` | `analysisResult()`, `criterion()` belongsTo · `evidenceQuotes()` hasMany | Skor AI per kriteria; `strengths`/`weaknesses` JSON |
| `EvidenceQuote` | `evidence_quotes` | `score()`, `answer()` belongsTo | `is_verified` = kutipan ditemukan di transkrip (`exact`/`normalized`); `match_method`/`match_score`/`matched_text` dari `QuoteVerifier` (ADR-008) |
| `ManualAssessment` | `manual_assessments` | `interviewSession()` belongsTo · `rater()` belongsTo User · `scores()` hasMany ManualScore | Penilaian manusia, satu per (sesi, penilai); `submitted_at` mengunci; `weightedScore()` |
| `ManualScore` | `manual_scores` | `manualAssessment()`, `criterion()` belongsTo | Skor manusia 1–5 per kriteria |

## Jenis relasi yang dipakai

- **One-to-many / many-to-one**: hampir semua (mis. `JobOpening → Criterion`, `Answer → TranscriptSegment`).
- **One-to-one**: `InterviewSession → AnalysisResult` (`hasOne` + kolom unik).
- **Many-to-many**: `InterviewSession ↔ User` lewat pivot `interview_session_interviewers` (panel wawancara).
- **Has-many-through**: `JobOpening → Question` (lewat `Criterion`), `AnalysisResult → EvidenceQuote` (lewat `Score`).
- **Rantai bersarang**: `JobOpening → Criterion → Question → Answer → TranscriptSegment`, di-eager-load misalnya `candidate.jobOpening.criteria.questions` di halaman sesi.

## Dua jalur penilaian

```
InterviewSession ──hasMany──► ManualAssessment ──hasMany──► ManualScore   (manusia, per penilai)
                 ──hasOne───► AnalysisResult   ──hasMany──► Score          (AI, satu per sesi)
                                                            └──hasMany──► EvidenceQuote
```

Keduanya menunjuk ke `Criterion` yang sama, sehingga skor AI dan skor manusia bisa dibandingkan per kriteria (metrik *agreement*, ADR-004).
