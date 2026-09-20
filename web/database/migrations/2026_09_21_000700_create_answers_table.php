<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('interview_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('question_id')->constrained()->restrictOnDelete();
            $table->string('audio_path')->nullable();
            $table->longText('transcript_raw')->nullable();
            $table->longText('transcript_edited')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            // Aggregates over transcript_segments, denormalised for quick listing.
            $table->unsignedInteger('avg_latency_ms')->nullable();
            $table->string('model_used', 64)->nullable();
            $table->timestamps();

            $table->unique(['interview_session_id', 'question_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('answers');
    }
};
