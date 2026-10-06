<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 1-5 integers -> 0-10 in steps of 0.5 (ADR-010, decided before any real rating existed).
     * Existing dev rows are mapped 1->0, 2->2.5, 3->5, 4->7.5, 5->10, which keeps each old
     * score on the reference point that describes the same kind of answer.
     */
    public function up(): void
    {
        Schema::table('scores', fn (Blueprint $t) => $t->decimal('score', 3, 1)->nullable()->change());
        Schema::table('manual_scores', fn (Blueprint $t) => $t->decimal('score', 3, 1)->change());

        foreach (['scores', 'manual_scores'] as $table) {
            DB::table($table)->whereNotNull('score')->update(['score' => DB::raw('(score - 1) * 2.5')]);
        }
    }

    public function down(): void
    {
        foreach (['scores', 'manual_scores'] as $table) {
            DB::table($table)->whereNotNull('score')->update(['score' => DB::raw('ROUND(score / 2.5) + 1')]);
        }

        Schema::table('scores', fn (Blueprint $t) => $t->unsignedTinyInteger('score')->nullable()->change());
        Schema::table('manual_scores', fn (Blueprint $t) => $t->unsignedTinyInteger('score')->change());
    }
};
