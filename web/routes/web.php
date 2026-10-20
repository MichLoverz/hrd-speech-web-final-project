<?php

use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\AnswerController;
use App\Http\Controllers\CandidateComparisonController;
use App\Http\Controllers\CandidateController;
use App\Http\Controllers\CriterionController;
use App\Http\Controllers\InterviewSessionController;
use App\Http\Controllers\JobOpeningController;
use App\Http\Controllers\LiveInterviewController;
use App\Http\Controllers\ManualAssessmentController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\QuestionController;
use App\Http\Controllers\RecordingController;
use App\Http\Controllers\ScorecardController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('job-openings.index'));
Route::get('/dashboard', fn () => redirect()->route('job-openings.index'))->name('dashboard');

Route::middleware('auth')->group(function () {
    // Plans page: display only, draft prices (config/plans.php).
    Route::view('/paket', 'plans.index')->name('plans.index');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Read access for every role; writes are gated by policies inside the controllers.
    Route::resource('job-openings', JobOpeningController::class);
    Route::get('job-openings/{jobOpening}/compare', CandidateComparisonController::class)->name('job-openings.compare');
    Route::resource('candidates', CandidateController::class);
    Route::get('candidates/{candidate}/cv', [CandidateController::class, 'cv'])->name('candidates.cv');
    Route::resource('interview-sessions', InterviewSessionController::class)->only(['index', 'create', 'store', 'show', 'destroy']);
    Route::post('interview-sessions/{interviewSession}/manual-assessment', [ManualAssessmentController::class, 'save'])->name('manual-assessments.save');

    // Live interview: consent -> record -> finish. Answers are written by the recorder JS.
    Route::get('interview-sessions/{interviewSession}/consent', [RecordingController::class, 'consent'])->name('recording.consent');
    Route::post('interview-sessions/{interviewSession}/consent', [RecordingController::class, 'storeConsent'])->name('recording.consent.store');
    Route::get('interview-sessions/{interviewSession}/record', [RecordingController::class, 'show'])->name('recording.show');
    Route::post('interview-sessions/{interviewSession}/finish', [RecordingController::class, 'finish'])->name('recording.finish');
    Route::put('interview-sessions/{interviewSession}/answers/{question}', [AnswerController::class, 'store'])->name('answers.store');
    Route::post('interview-sessions/{interviewSession}/answers/{question}/audio', [AnswerController::class, 'audio'])->name('answers.audio');
    Route::post('interview-sessions/{interviewSession}/answers/{question}/retranscribe', [AnswerController::class, 'retranscribe'])->name('answers.retranscribe');
    Route::patch('answers/{answer}/transcript', [AnswerController::class, 'updateTranscript'])->name('answers.transcript');

    // Continuous-interview mode (ADR-007): diarization + automatic question tracking, saved as it goes.
    Route::get('interview-sessions/{interviewSession}/live', [LiveInterviewController::class, 'show'])->name('live.show');
    Route::post('interview-sessions/{interviewSession}/live/segments', [LiveInterviewController::class, 'segments'])->name('live.segments');
    Route::post('interview-sessions/{interviewSession}/live/audio', [LiveInterviewController::class, 'audio'])->name('live.audio');
    Route::post('interview-sessions/{interviewSession}/live/finish', [LiveInterviewController::class, 'finish'])->name('live.finish');
    Route::post('interview-sessions/{interviewSession}/live/rebuild', [LiveInterviewController::class, 'rebuild'])->name('live.rebuild');
    Route::post('interview-sessions/{interviewSession}/live/reset', [LiveInterviewController::class, 'reset'])->name('live.reset');

    // Phase 4: draft scorecard (queued)
    Route::post('interview-sessions/{interviewSession}/scorecard', [ScorecardController::class, 'store'])->name('scorecard.store');
    Route::patch('segments/{segment}', [LiveInterviewController::class, 'updateSegment'])->name('segments.update');

    // Criteria and questions are edited inline from the job opening page.
    Route::middleware('role:hrd')->group(function () {
        Route::post('job-openings/{jobOpening}/criteria', [CriterionController::class, 'store'])->name('criteria.store');
        Route::put('job-openings/{jobOpening}/criteria/{criterion}', [CriterionController::class, 'update'])->name('criteria.update');
        Route::delete('job-openings/{jobOpening}/criteria/{criterion}', [CriterionController::class, 'destroy'])->name('criteria.destroy');

        Route::post('criteria/{criterion}/questions', [QuestionController::class, 'store'])->name('questions.store');
        Route::put('criteria/{criterion}/questions/{question}', [QuestionController::class, 'update'])->name('questions.update');
        Route::delete('criteria/{criterion}/questions/{question}', [QuestionController::class, 'destroy'])->name('questions.destroy');
    });

    // Account management is admin-only.
    Route::middleware('role:admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('users', [AdminUserController::class, 'index'])->name('users.index');
        Route::patch('users/{user}/role', [AdminUserController::class, 'updateRole'])->name('users.role');
    });
});

require __DIR__.'/auth.php';
