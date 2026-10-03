<?php

namespace App\Services\Nlp;

/**
 * Normalisation for matching quotes and picking evidence (ADR-004).
 *
 * Deliberately NOT the WER scorer's normaliser (tools/wer_eval.py): that one
 * also rewrites spoken numbers to digits, which here would let a quote
 * "match" words the candidate never said ("80 juta" vs "delapan juta").
 * Quotes are always checked against the original transcript; this only
 * forgives case, punctuation and hesitation sounds.
 */
class TextNormalizer
{
    /** Hesitations Whisper writes out ("eee", "hmm", "ehm") and an LLM usually drops when quoting. */
    private const FILLER = '/^(e+|e+h+m*|e*m+|h+m+|a+h+|u+h+|u+m+)$/u';

    /**
     * Lowercase words without punctuation or fillers. Hyphens and apostrophes join
     * ("query-nya" -> "querynya"), the same convention as `wer_eval.py --punct remove`.
     *
     * @return list<string>
     */
    public static function tokens(string $text): array
    {
        $text = mb_strtolower($text);
        $text = preg_replace("/[-'’]/u", '', $text);
        $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text);
        $words = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_filter($words, fn ($w) => ! preg_match(self::FILLER, $w)));
    }

    /**
     * Sentences on Whisper's punctuation. A run-on without punctuation is cut every
     * $maxWords words so one long stretch cannot swamp retrieval.
     *
     * @return list<string>
     */
    public static function sentences(string $text, int $maxWords = 40): array
    {
        $parts = preg_split('/(?<=[.!?…])\s+|\R+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY);
        $out = [];
        foreach ($parts as $part) {
            $words = preg_split('/\s+/u', trim($part), -1, PREG_SPLIT_NO_EMPTY);
            foreach (array_chunk($words, $maxWords) as $chunk) {
                $out[] = implode(' ', $chunk);
            }
        }

        return $out;
    }

    /**
     * First index at or after $from where $needle occurs in $haystack as a contiguous run.
     *
     * @param  list<string>  $haystack
     * @param  list<string>  $needle
     */
    public static function find(array $haystack, array $needle, int $from = 0): ?int
    {
        $n = count($needle);
        if ($n === 0) {
            return null;
        }
        for ($i = $from, $last = count($haystack) - $n; $i <= $last; $i++) {
            if (array_slice($haystack, $i, $n) === $needle) {
                return $i;
            }
        }

        return null;
    }
}
