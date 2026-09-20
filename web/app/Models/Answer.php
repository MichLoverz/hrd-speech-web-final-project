<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Answer extends Model
{
    use HasFactory;

    protected $fillable = [
        'interview_session_id',
        'question_id',
        'audio_path',
        'transcript_raw',
        'transcript_edited',
        'duration_ms',
        'avg_latency_ms',
        'model_used',
    ];

    protected function casts(): array
    {
        return [
            'duration_ms' => 'integer',
            'avg_latency_ms' => 'integer',
        ];
    }

    public function interviewSession(): BelongsTo
    {
        return $this->belongsTo(InterviewSession::class);
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }

    public function transcriptSegments(): HasMany
    {
        return $this->hasMany(TranscriptSegment::class)->orderBy('start_ms');
    }

    public function evidenceQuotes(): HasMany
    {
        return $this->hasMany(EvidenceQuote::class);
    }

    /** The transcript to show and analyse: the human-edited one if it exists, else the raw ASR output. */
    public function getTranscriptAttribute(): ?string
    {
        return $this->transcript_edited ?? $this->transcript_raw;
    }
}
