<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A human rater's scorecard for one session. One per (session, rater) so that
        // two raters can score the same session and be compared with each other and
        // with the AI scorecard (ADR-004).
        Schema::create('manual_assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('interview_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rater_id')->constrained('users')->cascadeOnDelete();
            $table->text('overall_notes')->nullable();
            // Null while the rater is still editing. Once set, the rater may view the AI scorecard.
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();

            $table->unique(['interview_session_id', 'rater_id']);
        });

        Schema::create('manual_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('manual_assessment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('criterion_id')->constrained('criteria')->cascadeOnDelete();
            $table->unsignedTinyInteger('score'); // 1-5
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['manual_assessment_id', 'criterion_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manual_scores');
        Schema::dropIfExists('manual_assessments');
    }
};
