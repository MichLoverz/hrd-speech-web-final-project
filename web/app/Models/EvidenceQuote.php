<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EvidenceQuote extends Model
{
    use HasFactory;

    protected $fillable = [
        'score_id',
        'answer_id',
        'quote_text',
        'supports',
        'start_ms',
        'end_ms',
        'is_verified',
    ];

    protected function casts(): array
    {
        return [
            'is_verified' => 'boolean',
        ];
    }

    public function score(): BelongsTo
    {
        return $this->belongsTo(Score::class);
    }

    public function answer(): BelongsTo
    {
        return $this->belongsTo(Answer::class);
    }
}
