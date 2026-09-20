<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evidence_quotes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('score_id')->constrained()->cascadeOnDelete();
            $table->foreignId('answer_id')->constrained()->cascadeOnDelete();
            $table->text('quote_text');
            $table->enum('supports', ['strength', 'weakness']);
            $table->unsignedInteger('start_ms')->nullable();
            $table->unsignedInteger('end_ms')->nullable();
            // True only if quote_text was found verbatim in the answer transcript (hallucination guard).
            $table->boolean('is_verified')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evidence_quotes');
    }
};
