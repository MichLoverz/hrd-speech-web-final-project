<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class InterviewSession extends Model
{
    use HasFactory;

    public const STATUSES = [
        'draft', 'recording', 'transcribing', 'analyzing', 'ready_for_review', 'approved',
    ];

    protected $fillable = [
        'candidate_id',
        'interviewer_id',
        'started_at',
        'ended_at',
        'status',
        'notes',
        'recording_mode',
        'audio_path',
        'audio_duration_ms',
        'asr_model',
        'speaker_model',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }

    /** Lead interviewer. */
    public function interviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'interviewer_id');
    }

    /** Full interview panel (many-to-many via pivot). */
    public function panel(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'interview_session_interviewers')
            ->withTimestamps();
    }

    public function answers(): HasMany
    {
        return $this->hasMany(Answer::class);
    }

    public function consents(): HasMany
    {
        return $this->hasMany(Consent::class);
    }

    /** All transcript segments of the session (continuous-interview mode), in stream order. */
    public function transcriptSegments(): HasMany
    {
        return $this->hasMany(TranscriptSegment::class)->orderBy('start_ms')->orderBy('id');
    }

    /** Human scorecards, one per rater. */
    public function manualAssessments(): HasMany
    {
        return $this->hasMany(ManualAssessment::class);
    }

    /** One AI scorecard per session (one-to-one). */
    public function analysisResult(): HasOne
    {
        return $this->hasOne(AnalysisResult::class);
    }

    public function hasConsent(): bool
    {
        return $this->consents()->exists();
    }
}
