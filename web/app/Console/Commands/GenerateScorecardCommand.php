<?php

namespace App\Console\Commands;

use App\Models\InterviewSession;
use App\Services\Llm\LlmUnavailable;
use App\Services\Nlp\NlpServiceUnavailable;
use App\Services\Scorecard\ScorecardGenerator;
use App\Services\Scorecard\ScorecardInvalid;
use App\Services\Scorecard\ScorecardNotAllowed;
use App\Support\ScoreScale;
use Illuminate\Console\Command;

/**
 * Same pipeline as the "Buat kartu skor" button, but in the foreground: no queue
 * worker needed, and the result is printed. Also the entry point for experiment
 * runs (--condition=retrieval for ADR-004 condition B).
 */
class GenerateScorecardCommand extends Command
{
    protected $signature = 'hirenote:scorecard {session : interview session id} {--condition=full : full | retrieval}';

    protected $description = 'Generate the draft scorecard for one session now and print it';

    public function handle(ScorecardGenerator $generator): int
    {
        $session = InterviewSession::find($this->argument('session'));
        if (! $session) {
            $this->error('Sesi tidak ditemukan.');

            return self::FAILURE;
        }

        $this->line("Sesi #{$session->id}".($session->is_test_data ? ' · DATA UJI' : '').' · kondisi '.$this->option('condition'));
        try {
            $result = $generator->generate($session, (string) $this->option('condition'));
        } catch (ScorecardNotAllowed|ScorecardInvalid|LlmUnavailable|NlpServiceUnavailable $e) {
            $this->error($e->getMessage());
            if ($e instanceof NlpServiceUnavailable) {
                $this->line('Layanan Python belum jalan? cd services && .venv\Scripts\activate && uvicorn app.main:app --port 8765');
            }

            return self::FAILURE;
        }

        $result->load('scores.criterion', 'scores.evidenceQuotes');
        $this->newLine();
        $this->line('Ringkasan: '.$result->overall_summary);
        $this->newLine();
        foreach ($result->scores as $score) {
            $this->line(sprintf('■ %s: %s', $score->criterion->name, $score->score === null ? 'tidak ada bukti' : ScoreScale::format($score->score).'/10'));
            $this->line('  '.$score->rationale);
            foreach ($score->evidenceQuotes as $q) {
                $this->line(sprintf('  [%s%s] %s', $q->match_method, $q->match_score !== null ? ' '.$q->match_score : '', $q->quote_text));
            }
        }

        $v = $result->raw_json['verification'];
        $this->newLine();
        $this->line(sprintf('Kutipan: exact %d · normalized %d · fuzzy %d · similar %d · none %d · dibuang %d · pindah jawaban %d',
            $v['exact'], $v['normalized'], $v['fuzzy'], $v['similar'], $v['none'], $v['dropped_quotes'], $v['reattributed']));
        $this->line(sprintf('Model %s · token in %s / out %s · %d ms%s · prompt %s',
            $result->model_used, $result->prompt_tokens ?? '?', $result->completion_tokens ?? '?', $result->llm_latency_ms,
            $result->cost_usd !== null ? sprintf(' · $%.6f', $result->cost_usd) : '', $result->prompt_version));

        return self::SUCCESS;
    }
}
