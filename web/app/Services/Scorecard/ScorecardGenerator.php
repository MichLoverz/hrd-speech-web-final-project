<?php

namespace App\Services\Scorecard;

use App\Models\AnalysisResult;
use App\Models\Answer;
use App\Models\InterviewSession;
use App\Services\Llm\ChatClient;
use App\Services\Nlp\EvidenceRetriever;
use App\Services\Nlp\QuoteMatch;
use App\Services\Nlp\QuoteVerifier;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Draft scorecard for one session (ADR-004, ADR-010):
 *
 *   guards -> [retrieval] -> prompt -> LLM -> validate -> verify every quote -> save
 *
 * Everything that can fail on the network (LLM, embedding service) happens before
 * the database transaction, so a failure never leaves half a scorecard behind.
 * Re-running replaces the previous draft (analysis_results stays one per session).
 */
class ScorecardGenerator
{
    private const RANK = [QuoteMatch::EXACT => 4, QuoteMatch::NORMALIZED => 3, QuoteMatch::FUZZY => 2, QuoteMatch::SIMILAR => 1, QuoteMatch::NONE => 0];

    public function __construct(
        private ChatClient $llm,
        private ScorecardPrompt $prompt,
        private QuoteVerifier $verifier,
        private EvidenceRetriever $retriever,
    ) {}

    /** Why this session must not be sent to the configured LLM, or null when it may. */
    public function refusal(InterviewSession $session): ?string
    {
        $hasText = $session->answers()->where(fn ($q) => $q->whereNotNull('transcript_raw')->orWhereNotNull('transcript_edited'))->exists();
        if (! $hasText) {
            return 'Belum ada jawaban bertranskrip di sesi ini.';
        }
        if ($this->llm->isTestDataOnly() && ! $session->is_test_data) {
            return "Penyedia LLM yang aktif ({$this->llm->provider()}) hanya boleh dipakai untuk data uji. Sesi peserta butuh penyedia berbayar yang tidak melatih model dengan data (ADR-009).";
        }
        if (! $session->is_test_data && ! $session->allowsExternalAi()) {
            return 'Kandidat belum menyetujui teks persetujuan v2, yang menyebut bahwa transkrip dikirim ke layanan AI. Mintakan persetujuan ulang dulu.';
        }

        return null;
    }

    public function generate(InterviewSession $session, string $condition = 'full'): AnalysisResult
    {
        if (! in_array($condition, ScorecardPrompt::CONDITIONS, true)) {
            throw new \InvalidArgumentException("unknown condition {$condition}");
        }
        if ($why = $this->refusal($session)) {
            throw new ScorecardNotAllowed($why);
        }

        $session->load('candidate.jobOpening.criteria.questions', 'answers.question');
        $evidence = $condition === 'retrieval' ? $this->retriever->retrieve($session) : null;

        $response = $this->llm->complete($this->prompt->messages($session, $condition, $evidence), $this->prompt->schema(), 'scorecard');
        $data = $response->json();
        if (! is_array($data) || ! is_array($data['criteria'] ?? null)) {
            throw new ScorecardInvalid('Model tidak mengembalikan JSON kartu skor yang valid: '.mb_substr($response->content, 0, 300));
        }

        [$rows, $stats] = $this->interpret($data, $session);

        return DB::transaction(function () use ($session, $condition, $response, $data, $rows, $stats, $evidence) {
            $result = AnalysisResult::updateOrCreate(['interview_session_id' => $session->id], [
                'raw_json' => ['response' => $data, 'verification' => $stats, 'retrieved' => $evidence],
                'overall_summary' => trim((string) ($data['overall_summary'] ?? '')),
                'status' => 'draft',
                'approved_by' => null,
                'approved_at' => null,
                'model_used' => $response->model,
                'provider' => $response->provider,
                'prompt_version' => ScorecardPrompt::VERSION,
                'condition' => $condition,
                'prompt_tokens' => $response->promptTokens,
                'completion_tokens' => $response->completionTokens,
                'reasoning_tokens' => $response->reasoningTokens,
                'cost_usd' => $response->costUsd,
                'llm_latency_ms' => $response->latencyMs,
                'error_message' => null,
                'generated_at' => now(),
            ]);

            $result->scores()->delete();   // evidence quotes go with them (cascade)
            foreach ($rows as $row) {
                $score = $result->scores()->create($row['score']);
                foreach ($row['quotes'] as $quote) {
                    $score->evidenceQuotes()->create($quote);
                }
            }
            $session->update(['status' => 'ready_for_review']);

            return $result;
        });
    }

    /**
     * Turns the model's JSON into rows, never trusting it: unknown or duplicate criteria are
     * dropped, scores outside 1-5 become "no evidence", every quote is verified, and
     * criteria the model skipped are added as unscored.
     *
     * @return array{0: list<array{score: array, quotes: list<array>}>, 1: array<string, int>}
     */
    private function interpret(array $data, InterviewSession $session): array
    {
        $criteria = $session->candidate->jobOpening->criteria->keyBy('id');
        $answers = $session->answers->keyBy('id');
        $stats = array_fill_keys(array_keys(self::RANK), 0) + ['dropped_quotes' => 0, 'reattributed' => 0, 'ignored_criteria' => 0, 'missing_criteria' => 0];

        $rows = [];
        foreach ($data['criteria'] as $item) {
            $cid = (int) ($item['criterion_id'] ?? 0);
            if (! $criteria->has($cid) || isset($rows[$cid])) {
                $stats['ignored_criteria']++;

                continue;
            }

            $score = (int) ($item['score'] ?? 0);
            $score = (($item['has_evidence'] ?? false) && $score >= 1 && $score <= 5) ? $score : null;

            $quotes = [];
            foreach ($item['evidence'] ?? [] as $ev) {
                $text = trim((string) ($ev['quote'] ?? ''));
                [$answer, $match] = $text === '' ? [null, null] : $this->locate($text, (int) ($ev['answer_id'] ?? 0), $answers, $stats);
                if (! $answer) {
                    $stats['dropped_quotes']++;

                    continue;
                }
                $stats[$match->method]++;
                $quotes[] = [
                    'answer_id' => $answer->id,
                    'quote_text' => $text,
                    'supports' => ($ev['supports'] ?? '') === 'weakness' ? 'weakness' : 'strength',
                    'start_ms' => $match->startMs,
                    'end_ms' => $match->endMs,
                    'is_verified' => $match->isVerified(),
                    'match_method' => $match->method,
                    'match_score' => $match->score,
                    'matched_text' => $match->matchedText,
                ];
            }

            $rows[$cid] = ['score' => [
                'criterion_id' => $cid,
                'score' => $score,
                'rationale' => trim((string) ($item['rationale'] ?? '')),
                'strengths' => array_values(array_filter(array_map('trim', (array) ($item['strengths'] ?? [])))),
                'weaknesses' => array_values(array_filter(array_map('trim', (array) ($item['weaknesses'] ?? [])))),
            ], 'quotes' => $quotes];
        }

        foreach ($criteria as $cid => $criterion) {
            if (! isset($rows[$cid])) {
                $stats['missing_criteria']++;
                $rows[$cid] = ['score' => ['criterion_id' => $cid, 'score' => null, 'rationale' => 'Model tidak menilai kriteria ini.',
                    'strengths' => [], 'weaknesses' => []], 'quotes' => []];
            }
        }

        return [array_values($rows), $stats];
    }

    /**
     * The answer a quote really comes from: the one the model named if the quote is there,
     * otherwise whichever answer matches best (models often cite the wrong answer_id).
     *
     * @return array{0: ?Answer, 1: ?QuoteMatch}
     */
    private function locate(string $quote, int $answerId, Collection $answers, array &$stats): array
    {
        $bestAnswer = $answers->get($answerId);
        $best = $bestAnswer ? $this->verifier->verify($quote, $bestAnswer) : null;
        if ($best?->isVerified()) {
            return [$bestAnswer, $best];
        }

        foreach ($answers as $answer) {
            if ($answer->id === $answerId) {
                continue;
            }
            $m = $this->verifier->verify($quote, $answer);
            if (! $best || self::RANK[$m->method] > self::RANK[$best->method]
                || (self::RANK[$m->method] === self::RANK[$best->method] && ($m->score ?? 0) > ($best->score ?? 0))) {
                [$best, $bestAnswer] = [$m, $answer];
            }
            if ($m->isVerified()) {
                break;
            }
        }
        if ($bestAnswer && $bestAnswer->id !== $answerId) {
            $stats['reattributed']++;
        }

        return [$bestAnswer, $best];
    }
}
