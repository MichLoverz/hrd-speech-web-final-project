<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A human rater's scorecard for one interview session (the baseline the AI scorecard is compared against). */
class ManualAssessment extends Model
{
    use HasFactory;

    protected $fillable = [
        'interview_session_id',
        'rater_id',
        'overall_notes',
        'submitted_at',
    ];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
        ];
    }

    public function interviewSession(): BelongsTo
    {
        return $this->belongsTo(InterviewSession::class);
    }

    public function rater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rater_id');
    }

    public function scores(): HasMany
    {
        return $this->hasMany(ManualScore::class);
    }

    public function isSubmitted(): bool
    {
        return $this->submitted_at !== null;
    }

    /** Weighted average on the 0-10 scale, using each criterion's weight. Null if no scores. */
    public function weightedScore(): ?float
    {
        $scores = $this->scores->loadMissing('criterion');
        $totalWeight = $scores->sum(fn ($s) => $s->criterion->weight);

        if ($totalWeight === 0) {
            return null;
        }

        return round($scores->sum(fn ($s) => $s->score * $s->criterion->weight) / $totalWeight, 2);
    }
}
