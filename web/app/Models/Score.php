<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Score extends Model
{
    use HasFactory;

    protected $fillable = [
        'analysis_result_id',
        'criterion_id',
        'score',
        'strengths',
        'weaknesses',
        'edited_by_human',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'strengths' => 'array',
            'weaknesses' => 'array',
            'edited_by_human' => 'boolean',
        ];
    }

    public function analysisResult(): BelongsTo
    {
        return $this->belongsTo(AnalysisResult::class);
    }

    public function criterion(): BelongsTo
    {
        return $this->belongsTo(Criterion::class);
    }

    public function evidenceQuotes(): HasMany
    {
        return $this->hasMany(EvidenceQuote::class);
    }
}
