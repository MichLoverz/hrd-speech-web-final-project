<?php

namespace App\Services\Nlp;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * Sentence embeddings from the Python service (POST /nlp/embed). Laravel cannot
 * run the model itself (ADR-004); vectors come back unit length, so cosine
 * similarity is a dot product.
 */
class EmbeddingClient
{
    private const BATCH = 500;

    /** Model name the service reported on the last call, for analysis_results / experiment logs. */
    public ?string $lastModel = null;

    /**
     * @param  list<string>  $texts
     * @param  'query'|'passage'  $kind  only matters for models trained with role prefixes (e5)
     * @return list<list<float>>
     */
    public function embed(array $texts, string $kind = 'passage'): array
    {
        $vectors = [];
        foreach (array_chunk(array_values($texts), self::BATCH) as $batch) {
            try {
                $response = Http::timeout(config('services.nlp.timeout'))
                    ->acceptJson()
                    ->post(config('services.nlp.http').'/nlp/embed', [
                        'texts' => $batch,
                        'kind' => $kind,
                        'model' => config('services.nlp.embed_model'),
                    ])
                    ->throw();
            } catch (ConnectionException|RequestException $e) {
                throw new NlpServiceUnavailable('NLP service unavailable: '.$e->getMessage(), previous: $e);
            }
            $this->lastModel = $response->json('model');
            array_push($vectors, ...$response->json('vectors'));
        }

        return $vectors;
    }

    /** @param list<float> $a @param list<float> $b */
    public static function cosine(array $a, array $b): float
    {
        $dot = 0.0;
        foreach ($a as $i => $v) {
            $dot += $v * $b[$i];
        }

        return $dot;
    }
}
