<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('interview_sessions', function (Blueprint $table) {
            // Read scripts and made-up interviews. Only these may go to a test-data-only LLM (Gemini free
            // tier), and they are excluded from every evaluation figure.
            $table->boolean('is_test_data')->default(false)->after('status');
        });

        Schema::table('analysis_results', function (Blueprint $table) {
            $table->string('condition', 16)->nullable()->after('prompt_version');   // full | retrieval (ADR-004 A/B)
            $table->string('provider', 32)->nullable()->after('model_used');
            $table->unsignedInteger('prompt_tokens')->nullable()->after('condition');
            $table->unsignedInteger('completion_tokens')->nullable()->after('prompt_tokens');
            $table->unsignedInteger('reasoning_tokens')->nullable()->after('completion_tokens');
            $table->decimal('cost_usd', 10, 6)->nullable()->after('reasoning_tokens');   // billed amount, OpenRouter only
            $table->unsignedInteger('llm_latency_ms')->nullable()->after('cost_usd');
            $table->text('error_message')->nullable()->after('llm_latency_ms');           // last failed attempt, shown to HRD
            $table->timestamp('generated_at')->nullable()->after('error_message');
        });

        Schema::table('scores', function (Blueprint $table) {
            // Null = the transcript holds no evidence for this criterion. Honest "cannot tell" beats a forced number.
            $table->unsignedTinyInteger('score')->nullable()->change();
            $table->text('rationale')->nullable()->after('score');
        });
    }

    public function down(): void
    {
        Schema::table('scores', function (Blueprint $table) {
            $table->dropColumn('rationale');
            $table->unsignedTinyInteger('score')->nullable(false)->change();
        });
        Schema::table('analysis_results', function (Blueprint $table) {
            $table->dropColumn(['condition', 'provider', 'prompt_tokens', 'completion_tokens', 'reasoning_tokens',
                'cost_usd', 'llm_latency_ms', 'error_message', 'generated_at']);
        });
        Schema::table('interview_sessions', function (Blueprint $table) {
            $table->dropColumn('is_test_data');
        });
    }
};
