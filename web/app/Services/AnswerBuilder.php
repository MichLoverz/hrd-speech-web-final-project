<?php

namespace App\Services;

use App\Models\Answer;
use App\Models\InterviewSession;
use Illuminate\Support\Facades\DB;

/**
 * Derives per-question answers from a continuous interview's segments:
 * for each question, the candidate's final segments labelled with that
 * question, in stream order, joined into transcript_raw. Idempotent, so
 * it can be re-run after a human relabels speakers or moves segments.
 */
class AnswerBuilder
{
    /** @return array{answers:int, unassigned:int} */
    public function build(InterviewSession $session): array
    {
        $session->loadMissing('candidate.jobOpening.criteria.questions', 'transcriptSegments');
        $questions = $session->candidate->jobOpening->criteria->flatMap->questions;
        $segments = $session->transcriptSegments;
        $usePrompt = (bool) config('services.asr.use_prompt');

        return DB::transaction(function () use ($session, $questions, $segments, $usePrompt) {
            $built = 0;
            foreach ($questions as $question) {
                $mine = $segments->where('question_id', $question->id);
                $spoken = $mine->where('is_final', true)->where('speaker', 'candidate')->sortBy(['start_ms', 'id']);

                $existing = Answer::where('interview_session_id', $session->id)->where('question_id', $question->id)->first();

                if ($spoken->isEmpty()) {
                    // Nothing from the candidate under this question. Keep a human-edited answer, drop the rest.
                    if ($existing && $existing->transcript_edited === null) {
                        $existing->transcriptSegments()->update(['answer_id' => null]);
                        $existing->delete();
                    }
                    continue;
                }

                $finalLatencies = $spoken->pluck('latency_ms')->filter(fn ($v) => $v !== null);
                $answer = Answer::updateOrCreate(
                    ['interview_session_id' => $session->id, 'question_id' => $question->id],
                    [
                        'transcript_raw' => $spoken->pluck('text')->map(fn ($t) => trim((string) $t))->filter()->implode(' '),
                        'duration_ms' => (int) $spoken->sum(fn ($s) => max(0, $s->end_ms - $s->start_ms)),
                        'avg_latency_ms' => $finalLatencies->isEmpty() ? null : (int) round($finalLatencies->avg()),
                        'model_used' => $session->asr_model,
                        'asr_mode' => 'interview',
                        'asr_prompt' => $usePrompt ? $question->text : null,
                        'transcribed_at' => now(),
                    ],
                );

                // Every segment under this question (both speakers, partials too) hangs off the answer.
                $session->transcriptSegments()->where('question_id', $question->id)->update(['answer_id' => $answer->id]);
                $built++;
            }

            $unassigned = $session->transcriptSegments()->whereNull('question_id')->where('is_final', true)->count();

            return ['answers' => $built, 'unassigned' => $unassigned];
        });
    }
}
