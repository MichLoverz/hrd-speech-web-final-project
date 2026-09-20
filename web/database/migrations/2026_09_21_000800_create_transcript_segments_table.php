<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per transcript emission (partial or final) from the ASR service.
        // This table is the raw data for the latency / real-time-factor analysis.
        Schema::create('transcript_segments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('answer_id')->constrained()->cascadeOnDelete();
            $table->text('text');
            // Position within the answer's audio, in milliseconds.
            $table->unsignedInteger('start_ms');
            $table->unsignedInteger('end_ms');
            $table->boolean('is_final')->default(false);
            $table->decimal('confidence', 5, 4)->nullable();

            // Timing instrumentation, all on the ASR service clock (ms precision).
            $table->timestamp('chunk_received_at', 3)->nullable();
            $table->timestamp('inference_started_at', 3)->nullable();
            $table->timestamp('inference_ended_at', 3)->nullable();
            $table->timestamp('emitted_at', 3)->nullable();
            // emitted_at - chunk_received_at, measured on the ASR service.
            $table->unsignedInteger('latency_ms')->nullable();
            // Measured in the browser: last chunk sent -> transcript received. Same clock, so no skew.
            $table->unsignedInteger('e2e_latency_ms')->nullable();
            $table->unsignedInteger('audio_duration_ms')->nullable();
            // inference time / audio duration. Below 1 means faster than real time.
            $table->decimal('real_time_factor', 6, 3)->nullable();
            $table->timestamps();

            $table->index(['answer_id', 'is_final']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transcript_segments');
    }
};
