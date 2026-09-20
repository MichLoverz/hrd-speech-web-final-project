<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_id')->constrained()->cascadeOnDelete();
            $table->foreignId('interview_session_id')->constrained()->cascadeOnDelete();
            $table->timestamp('agreed_at');
            $table->string('ip_address', 45)->nullable();
            // Which version of the consent wording the candidate saw, for auditability.
            $table->string('consent_text_version', 16);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consents');
    }
};
