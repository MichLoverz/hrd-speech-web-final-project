<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Continuous-interview mode (ADR-007): one audio stream for the whole
 * session, every segment labelled with a speaker and the question it
 * belongs to. Answers are then *derived* from candidate segments per
 * question, instead of being recorded one at a time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transcript_segments', function (Blueprint $table) {
            // A segment now belongs to the session; answer_id is filled in when answers are built.
            $table->foreignId('interview_session_id')->nullable()->after('answer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('question_id')->nullable()->after('interview_session_id')->constrained()->nullOnDelete();
            $table->string('speaker', 16)->nullable()->after('question_id');            // interviewer | candidate | unknown
            $table->decimal('speaker_similarity', 5, 3)->nullable()->after('speaker');
            $table->boolean('speaker_edited')->default(false)->after('speaker_similarity'); // human relabelled
            $table->unsignedInteger('client_seq')->nullable()->after('speaker_edited');  // seq from the service, dedupes retries
            $table->string('question_reason', 16)->nullable()->after('client_seq');     // match | phrase | manual | edited

            $table->index(['interview_session_id', 'client_seq']);
        });

        // MariaDB: dropping the FK is required before the column can become nullable.
        Schema::table('transcript_segments', function (Blueprint $table) {
            $table->dropForeign(['answer_id']);
        });
        Schema::table('transcript_segments', function (Blueprint $table) {
            $table->unsignedBigInteger('answer_id')->nullable()->change();
            $table->foreign('answer_id')->references('id')->on('answers')->nullOnDelete();
        });

        Schema::table('interview_sessions', function (Blueprint $table) {
            $table->string('recording_mode', 16)->nullable()->after('status');   // interview | question
            $table->string('audio_path')->nullable()->after('recording_mode');   // whole-session WAV
            $table->unsignedInteger('audio_duration_ms')->nullable()->after('audio_path');
            $table->string('asr_model', 64)->nullable()->after('audio_duration_ms');
            $table->string('speaker_model', 96)->nullable()->after('asr_model');
        });
    }

    public function down(): void
    {
        Schema::table('interview_sessions', function (Blueprint $table) {
            $table->dropColumn(['recording_mode', 'audio_path', 'audio_duration_ms', 'asr_model', 'speaker_model']);
        });
        Schema::table('transcript_segments', function (Blueprint $table) {
            $table->dropForeign(['interview_session_id']);
            $table->dropForeign(['question_id']);
            $table->dropIndex(['interview_session_id', 'client_seq']);
            $table->dropColumn(['interview_session_id', 'question_id', 'speaker', 'speaker_similarity',
                'speaker_edited', 'client_seq', 'question_reason']);
        });
    }
};
