<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const ROLE_ADMIN = 'admin';
    public const ROLE_HRD = 'hrd';
    public const ROLE_INTERVIEWER = 'interviewer';

    public const ROLES = [self::ROLE_ADMIN, self::ROLE_HRD, self::ROLE_INTERVIEWER];

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function hasRole(string ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    /** Job openings this user created. */
    public function jobOpenings(): HasMany
    {
        return $this->hasMany(JobOpening::class, 'created_by');
    }

    /** Sessions where this user is the lead interviewer. */
    public function ledSessions(): HasMany
    {
        return $this->hasMany(InterviewSession::class, 'interviewer_id');
    }

    /** Sessions where this user sits on the panel (many-to-many). */
    public function panelSessions(): BelongsToMany
    {
        return $this->belongsToMany(InterviewSession::class, 'interview_session_interviewers')
            ->withTimestamps();
    }

    /** Scorecards this user approved. */
    public function approvedAnalyses(): HasMany
    {
        return $this->hasMany(AnalysisResult::class, 'approved_by');
    }
}
