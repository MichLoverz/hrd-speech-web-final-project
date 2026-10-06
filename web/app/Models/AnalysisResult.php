<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class AnalysisResult extends Model
{
    use HasFactory;

    public const STATUSES = ['draft', 'edited', 'approved'];

    protected $fillable = [
        'interview_session_id',
        'raw_json',
        'overall_summary',
        'status',
        'approved_by',
        'approved_at',
        'model_used',
        'prompt_version',
        'condition',
        'provider',
        'prompt_tokens',
        'completion_tokens',
        'reasoning_tokens',
        'cost_usd',
        'llm_latency_ms',
        'error_message',
        'generated_at',
    ];

    protected function casts(): array
    {
        return [
            'raw_json' => 'array',
            'approved_at' => 'datetime',
            'generated_at' => 'datetime',
            'cost_usd' => 'float',
        ];
    }

    public function interviewSession(): BelongsTo
    {
        return $this->belongsTo(InterviewSession::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function scores(): HasMany
    {
        return $this->hasMany(Score::class);
    }

    /**
     * Weighted mean over the criteria the model could score. Criteria without evidence are
     * left out rather than counted as zero, so compare with a human score on the same criteria.
     */
    public function weightedScore(): ?float
    {
        $scored = $this->scores->loadMissing('criterion')->whereNotNull('score');
        $totalWeight = $scored->sum(fn ($s) => $s->criterion->weight);

        return $totalWeight === 0 ? null : round($scored->sum(fn ($s) => $s->score * $s->criterion->weight) / $totalWeight, 2);
    }

    /** Every evidence quote on this scorecard, across all criteria. */
    public function evidenceQuotes(): HasManyThrough
    {
        return $this->hasManyThrough(EvidenceQuote::class, Score::class);
    }
}
