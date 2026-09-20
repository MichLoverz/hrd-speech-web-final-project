<?php

use App\Http\Controllers\CandidateController;
use App\Http\Controllers\CriterionController;
use App\Http\Controllers\InterviewSessionController;
use App\Http\Controllers\JobOpeningController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\QuestionController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('job-openings.index'));
Route::get('/dashboard', fn () => redirect()->route('job-openings.index'))->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Read access for every role; writes are gated by policies inside the controllers.
    Route::resource('job-openings', JobOpeningController::class);
    Route::resource('candidates', CandidateController::class);
    Route::get('candidates/{candidate}/cv', [CandidateController::class, 'cv'])->name('candidates.cv');
    Route::resource('interview-sessions', InterviewSessionController::class)->only(['index', 'create', 'store', 'show', 'destroy']);

    // Criteria and questions are edited inline from the job opening page.
    Route::middleware('role:hrd')->group(function () {
        Route::post('job-openings/{jobOpening}/criteria', [CriterionController::class, 'store'])->name('criteria.store');
        Route::put('job-openings/{jobOpening}/criteria/{criterion}', [CriterionController::class, 'update'])->name('criteria.update');
        Route::delete('job-openings/{jobOpening}/criteria/{criterion}', [CriterionController::class, 'destroy'])->name('criteria.destroy');

        Route::post('criteria/{criterion}/questions', [QuestionController::class, 'store'])->name('questions.store');
        Route::put('criteria/{criterion}/questions/{question}', [QuestionController::class, 'update'])->name('questions.update');
        Route::delete('criteria/{criterion}/questions/{question}', [QuestionController::class, 'destroy'])->name('questions.destroy');
    });
});

require __DIR__.'/auth.php';

