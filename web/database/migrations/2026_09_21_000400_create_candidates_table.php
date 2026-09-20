<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_opening_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('cv_path')->nullable();
            $table->unsignedBigInteger('expected_salary')->nullable();
            $table->enum('status', ['applied', 'interviewing', 'evaluated', 'hired', 'rejected'])->default('applied');
            $table->timestamps();

            $table->index(['job_opening_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidates');
    }
};
