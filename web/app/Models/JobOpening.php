<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class JobOpening extends Model
{
    use HasFactory;

    public const STATUSES = ['draft', 'open', 'closed'];

    protected $fillable = [
        'title',
        'department',
        'description',
        'salary_min',
        'salary_max',
        'status',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'salary_min' => 'integer',
            'salary_max' => 'integer',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function criteria(): HasMany
    {
        return $this->hasMany(Criterion::class)->orderBy('id');
    }

    /** All questions across all criteria of this opening (has-many-through). */
    public function questions(): HasManyThrough
    {
        return $this->hasManyThrough(Question::class, Criterion::class);
    }

    public function candidates(): HasMany
    {
        return $this->hasMany(Candidate::class);
    }

    /** Scope: openings visible to the given user (admins see everything). */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $user->isAdmin() ? $query : $query->where('created_by', $user->id);
    }

    /** Scope: simple title/department search. */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if ($term === null || $term === '') {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term) {
            $q->where('title', 'like', "%{$term}%")
                ->orWhere('department', 'like', "%{$term}%");
        });
    }
}
