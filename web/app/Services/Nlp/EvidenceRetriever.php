<?php

namespace App\Services\Nlp;

use App\Models\InterviewSession;

/**
 * Retrieval step of ADR-004 condition B: for each criterion, the k transcript
 * sentences closest to "name. description", taken from every answer in the
 * session, not only the answers to that criterion's own questions — candidates
 * often show a skill while answering something else.
 *
 * The LLM then sees only these sentences instead of the whole transcript
 * (condition A). Whether that helps is an experiment, not an assumption.
 */
class EvidenceRetriever
{
    /** Sentences shorter than this ("Ya.", "Oke, siap.") carry no evidence. */
    private const MIN_TOKENS = 3;

    public function __construct(private EmbeddingClient $embeddings) {}

    /**
     * @return array<int, list<array{answer_id:int, question_id:int, text:string, score:float}>> keyed by criterion id
     */
    public function retrieve(InterviewSession $session, ?int $k = null): array
    {
        $k ??= (int) config('services.nlp.retrieval_top_k');
        $session->loadMissing('answers', 'candidate.jobOpening.criteria');
        $criteria = $session->candidate->jobOpening->criteria;

        $pool = [];
        foreach ($session->answers as $answer) {
            foreach (TextNormalizer::sentences((string) $answer->transcript) as $sentence) {
                if (count(TextNormalizer::tokens($sentence)) >= self::MIN_TOKENS) {
                    $pool[] = ['answer_id' => $answer->id, 'question_id' => $answer->question_id, 'text' => $sentence];
                }
            }
        }
        if ($pool === [] || $criteria->isEmpty()) {
            return [];
        }

        $queries = $criteria->map(fn ($c) => trim($c->name.'. '.$c->description))->values()->all();
        $queryVectors = $this->embeddings->embed($queries, 'query');
        $poolVectors = $this->embeddings->embed(array_column($pool, 'text'), 'passage');

        $result = [];
        foreach ($criteria->values() as $ci => $criterion) {
            $ranked = [];
            foreach ($pool as $pi => $item) {
                $ranked[] = $item + ['score' => round(EmbeddingClient::cosine($queryVectors[$ci], $poolVectors[$pi]), 4)];
            }
            usort($ranked, fn ($a, $b) => $b['score'] <=> $a['score']);
            $result[$criterion->id] = array_slice($ranked, 0, $k);
        }

        return $result;
    }
}
