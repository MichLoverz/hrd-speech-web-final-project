<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Criterion extends Model
{
    use HasFactory;

    // Eloquent would otherwise guess "criteria" -> "criterions".
    protected $table = 'criteria';

    protected $fillable = [
        'job_opening_id',
        'name',
        'weight',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'weight' => 'integer',
        ];
    }

    public function jobOpening(): BelongsTo
    {
        return $this->belongsTo(JobOpening::class);
    }

    public function questions(): HasMany
    {
        return $this->hasMany(Question::class)->orderBy('position');
    }

    /** AI scores. */
    public function scores(): HasMany
    {
        return $this->hasMany(Score::class);
    }

    /** Human scores. */
    public function manualScores(): HasMany
    {
        return $this->hasMany(ManualScore::class);
    }
}
