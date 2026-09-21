<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TranscriptSegment extends Model
{
    use HasFactory;

    protected $fillable = [
        'answer_id',
        'interview_session_id',
        'question_id',
        'speaker',
        'speaker_similarity',
        'speaker_edited',
        'client_seq',
        'question_reason',
        'text',
        'start_ms',
        'end_ms',
        'is_final',
        'confidence',
        'chunk_received_at',
        'inference_started_at',
        'inference_ended_at',
        'emitted_at',
        'latency_ms',
        'e2e_latency_ms',
        'audio_duration_ms',
        'real_time_factor',
    ];

    protected function casts(): array
    {
        return [
            'is_final' => 'boolean',
            'speaker_edited' => 'boolean',
            'speaker_similarity' => 'float',
            'confidence' => 'float',
            'chunk_received_at' => 'datetime',
            'inference_started_at' => 'datetime',
            'inference_ended_at' => 'datetime',
            'emitted_at' => 'datetime',
            'latency_ms' => 'integer',
            'e2e_latency_ms' => 'integer',
            'audio_duration_ms' => 'integer',
            'real_time_factor' => 'float',
        ];
    }

    public function answer(): BelongsTo
    {
        return $this->belongsTo(Answer::class);
    }

    public function interviewSession(): BelongsTo
    {
        return $this->belongsTo(InterviewSession::class);
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }
}
