<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('analysis_result_id')->constrained()->cascadeOnDelete();
            $table->foreignId('criterion_id')->constrained('criteria')->cascadeOnDelete();
            $table->unsignedTinyInteger('score'); // 1-5
            $table->json('strengths')->nullable();
            $table->json('weaknesses')->nullable();
            $table->boolean('edited_by_human')->default(false);
            $table->timestamps();

            $table->unique(['analysis_result_id', 'criterion_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scores');
    }
};
