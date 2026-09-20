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
    ];

    protected function casts(): array
    {
        return [
            'raw_json' => 'array',
            'approved_at' => 'datetime',
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

    /** Every evidence quote on this scorecard, across all criteria. */
    public function evidenceQuotes(): HasManyThrough
    {
        return $this->hasManyThrough(EvidenceQuote::class, Score::class);
    }
}
