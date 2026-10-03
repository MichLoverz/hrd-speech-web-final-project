<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * is_verified alone cannot tell "the LLM paraphrased" from "the LLM made it up",
     * and the verified-quote-rate metric (ADR-004) needs that distinction.
     */
    public function up(): void
    {
        Schema::table('evidence_quotes', function (Blueprint $table) {
            // exact | normalized | similar | none — see App\Services\Nlp\QuoteMatch
            $table->string('match_method', 16)->nullable()->after('is_verified');
            // cosine similarity, only for similar / none
            $table->decimal('match_score', 5, 4)->nullable()->after('match_method');
            // the transcript sentence(s) a paraphrased quote was matched to, so HRD can compare
            $table->text('matched_text')->nullable()->after('match_score');
        });
    }

    public function down(): void
    {
        Schema::table('evidence_quotes', function (Blueprint $table) {
            $table->dropColumn(['match_method', 'match_score', 'matched_text']);
        });
    }
};
