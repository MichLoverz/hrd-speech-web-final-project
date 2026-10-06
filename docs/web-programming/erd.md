# ERD HireNote

Diturunkan dari `web/database/migrations/`. Perbarui setiap ada perubahan skema.

```mermaid
erDiagram
    users ||--o{ job_openings : "created_by"
    users ||--o{ interview_sessions : "interviewer_id (utama)"
    users }o--o{ interview_sessions : "interview_session_interviewers (panel)"
    users ||--o{ analysis_results : "approved_by"

    job_openings ||--o{ criteria : ""
    job_openings ||--o{ candidates : ""
    criteria ||--o{ questions : ""
    criteria ||--o{ scores : ""

    candidates ||--o{ interview_sessions : ""
    candidates ||--o{ consents : ""
    interview_sessions ||--o{ consents : ""
    interview_sessions ||--o{ answers : ""
    interview_sessions ||--|| analysis_results : "one-to-one"

    questions ||--o{ answers : ""
    answers ||--o{ transcript_segments : ""
    answers ||--o{ evidence_quotes : ""

    analysis_results ||--o{ scores : ""
    scores ||--o{ evidence_quotes : ""

    interview_sessions ||--o{ manual_assessments : ""
    users ||--o{ manual_assessments : "rater_id"
    manual_assessments ||--o{ manual_scores : ""
    criteria ||--o{ manual_scores : ""

    users {
        bigint id PK
        string name
        string email
        enum role "admin | hrd | interviewer"
    }
    job_openings {
        bigint id PK
        string title
        string department
        bigint salary_min
        bigint salary_max
        enum status "draft | open | closed"
        bigint created_by FK
    }
    criteria {
        bigint id PK
        bigint job_opening_id FK
        string name
        tinyint weight
    }
    questions {
        bigint id PK
        bigint criterion_id FK
        text text
        smallint position
    }
    candidates {
        bigint id PK
        bigint job_opening_id FK
        string name
        string cv_path
        bigint expected_salary
        enum status
    }
    interview_sessions {
        bigint id PK
        bigint candidate_id FK
        bigint interviewer_id FK
        timestamp started_at
        timestamp ended_at
        enum status "draft ... approved"
        boolean is_test_data "naskah/rekaan, bukan peserta"
    }
    interview_session_interviewers {
        bigint interview_session_id PK_FK
        bigint user_id PK_FK
    }
    consents {
        bigint id PK
        bigint candidate_id FK
        bigint interview_session_id FK
        timestamp agreed_at
        string ip_address
        string consent_text_version
    }
    answers {
        bigint id PK
        bigint interview_session_id FK
        bigint question_id FK
        string audio_path
        longtext transcript_raw
        longtext transcript_edited
        int avg_latency_ms
        string model_used
    }
    transcript_segments {
        bigint id PK
        bigint answer_id FK
        text text
        boolean is_final
        timestamp chunk_received_at
        timestamp inference_started_at
        timestamp inference_ended_at
        timestamp emitted_at
        int latency_ms
        int e2e_latency_ms
        decimal real_time_factor
    }
    analysis_results {
        bigint id PK
        bigint interview_session_id FK "unique"
        json raw_json
        enum status "draft | edited | approved"
        bigint approved_by FK
        string prompt_version
        string condition "full | retrieval"
        string model_used
        string provider
        int prompt_tokens
        int completion_tokens
        decimal cost_usd
        int llm_latency_ms
        text error_message
    }
    scores {
        bigint id PK
        bigint analysis_result_id FK
        bigint criterion_id FK
        decimal score "0-10 langkah 0,5; null = tidak ada bukti"
        text rationale
        json strengths
        json weaknesses
        boolean edited_by_human
    }
    evidence_quotes {
        bigint id PK
        bigint score_id FK
        bigint answer_id FK
        text quote_text
        enum supports "strength | weakness"
        boolean is_verified
        string match_method "exact | normalized | fuzzy | similar | none"
        decimal match_score
        text matched_text
    }
    manual_assessments {
        bigint id PK
        bigint interview_session_id FK
        bigint rater_id FK
        text overall_notes
        timestamp submitted_at
    }
    manual_scores {
        bigint id PK
        bigint manual_assessment_id FK
        bigint criterion_id FK
        decimal score "0-10 langkah 0,5"
        text notes
    }
```

## Jenis relasi yang terwakili

| Jenis | Contoh | Di kode |
|---|---|---|
| One-to-many | `job_openings → criteria`, `answers → transcript_segments`, `manual_assessments → manual_scores` | `hasMany` / `belongsTo` |
| One-to-one | `interview_sessions → analysis_results` | `hasOne` + kolom unik |
| Many-to-many | `interview_sessions ↔ users` (panel) | `belongsToMany` via `interview_session_interviewers` |
| Has-many-through | `job_openings → questions` (lewat `criteria`), `analysis_results → evidence_quotes` (lewat `scores`) | `hasManyThrough` |
| Rantai bersarang 4 tingkat | `job_openings → criteria → questions → answers → transcript_segments` | eager load `criteria.questions` |
