<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ManualScore extends Model
{
    use HasFactory;

    protected $fillable = [
        'manual_assessment_id',
        'criterion_id',
        'score',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'float',
        ];
    }

    public function manualAssessment(): BelongsTo
    {
        return $this->belongsTo(ManualAssessment::class);
    }

    public function criterion(): BelongsTo
    {
        return $this->belongsTo(Criterion::class);
    }
}
