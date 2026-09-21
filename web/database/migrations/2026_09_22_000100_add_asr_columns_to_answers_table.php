<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('answers', function (Blueprint $table) {
            // 'live' = streamed from the browser while speaking; 'file' = re-transcribed from the saved WAV.
            $table->string('asr_mode', 16)->nullable()->after('model_used');
            // The initial_prompt sent to Whisper (usually the question text). Null = no prompt. Kept for the
            // with/without-prompt experiment.
            $table->text('asr_prompt')->nullable()->after('asr_mode');
            $table->timestamp('transcribed_at')->nullable()->after('asr_prompt');
        });
    }

    public function down(): void
    {
        Schema::table('answers', function (Blueprint $table) {
            $table->dropColumn(['asr_mode', 'asr_prompt', 'transcribed_at']);
        });
    }
};
