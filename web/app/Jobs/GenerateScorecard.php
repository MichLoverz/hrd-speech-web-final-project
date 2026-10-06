<?php

namespace App\Jobs;

use App\Models\AnalysisResult;
use App\Models\InterviewSession;
use App\Services\Scorecard\ScorecardGenerator;
use App\Services\Scorecard\ScorecardNotAllowed;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Runs the scorecard pipeline off the request: an LLM call can take a minute,
 * and a provider overload should be retried later, not shown as a crash.
 */
class GenerateScorecard implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** Seconds before the 2nd and 3rd attempt; the client already retried quick overloads itself. */
    public array $backoff = [30, 120];

    public int $timeout = 600;

    public function __construct(
        public InterviewSession $session,
        public string $condition = 'full',
        public string $statusBefore = 'ready_for_review',
    ) {}

    public function handle(ScorecardGenerator $generator): void
    {
        try {
            $generator->generate($this->session, $this->condition);
        } catch (ScorecardNotAllowed $e) {
            $this->fail($e);   // waiting will not fix a missing consent
        }
    }

    /** After the last attempt: keep any earlier draft, show the error, put the session back. */
    public function failed(?Throwable $e): void
    {
        AnalysisResult::updateOrCreate(
            ['interview_session_id' => $this->session->id],
            ['error_message' => mb_substr($e?->getMessage() ?? 'Gagal tanpa pesan.', 0, 1000)],
        );
        $this->session->update(['status' => $this->statusBefore]);
    }
}
