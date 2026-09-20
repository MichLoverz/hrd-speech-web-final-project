<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('interview_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_id')->constrained()->cascadeOnDelete();
            // Lead interviewer. Additional panel members live in interview_session_interviewers.
            $table->foreignId('interviewer_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->enum('status', [
                'draft', 'recording', 'transcribing', 'analyzing', 'ready_for_review', 'approved',
            ])->default('draft');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['candidate_id', 'status']);
        });

        // Pivot: many-to-many between sessions and users (the interview panel).
        Schema::create('interview_session_interviewers', function (Blueprint $table) {
            $table->foreignId('interview_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['interview_session_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interview_session_interviewers');
        Schema::dropIfExists('interview_sessions');
    }
};
