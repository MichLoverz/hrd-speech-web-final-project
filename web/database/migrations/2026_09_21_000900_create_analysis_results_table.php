<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analysis_results', function (Blueprint $table) {
            $table->id();
            // One scorecard per session (one-to-one). Re-running analysis replaces this row's content.
            $table->foreignId('interview_session_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('raw_json')->nullable();
            $table->text('overall_summary')->nullable();
            $table->enum('status', ['draft', 'edited', 'approved'])->default('draft');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('model_used', 96)->nullable();
            $table->string('prompt_version', 16)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analysis_results');
    }
};
