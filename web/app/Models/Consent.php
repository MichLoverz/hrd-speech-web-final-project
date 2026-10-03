<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Consent extends Model
{
    use HasFactory;

    /** Bump this whenever the consent wording changes. Stored on every consent row. */
    public const CURRENT_TEXT_VERSION = 'v2';

    /**
     * Versions whose wording tells the candidate the transcript TEXT goes to an external
     * AI service for the draft scorecard. v1 only promised that audio stays local.
     */
    public const EXTERNAL_AI_VERSIONS = ['v2'];

    protected $fillable = [
        'candidate_id',
        'interview_session_id',
        'agreed_at',
        'ip_address',
        'consent_text_version',
    ];

    protected function casts(): array
    {
        return [
            'agreed_at' => 'datetime',
        ];
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }

    public function interviewSession(): BelongsTo
    {
        return $this->belongsTo(InterviewSession::class);
    }
}
