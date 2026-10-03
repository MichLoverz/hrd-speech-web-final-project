<?php

namespace App\Services\Nlp;

use App\Models\Answer;

/**
 * Hallucination guard for the scorecard (ADR-004, ADR-008): every quote the LLM
 * offers as evidence is checked against the transcript the LLM was given.
 *
 *   1. exact       verbatim substring
 *   2. normalized  same word sequence ignoring case, punctuation, fillers
 *   3. fuzzy       a run of words spelled almost the same (character similarity
 *                  >= fuzzy threshold) - typically the LLM silently "fixing" an
 *                  ASR error ("menabakan" -> "menambahkan")
 *   4. similar     cosine to a word window of the transcript >= similarity threshold
 *   5. none        otherwise
 *
 * Only 1-2 count as verified; 3-4 show HRD the transcript text next to the quote.
 * A quote with "..." is matched fragment by fragment, in order, for 1-2. Tiers 1-3
 * run without the Python service; tier 4 throws NlpServiceUnavailable when it is
 * down, so the caller can retry instead of storing a wrong "none".
 */
class QuoteVerifier
{
    /** Word-window sizes for the semantic tier. Whisper rarely ends sentences, so sentences are no unit. */
    private const WINDOWS = [8, 16, 24];
    private const STRIDE = 4;

    private float $threshold;
    private float $fuzzyThreshold;

    /** @var array<string, array{0: list<string>, 1: list<list<float>>}> window texts + vectors per transcript */
    private array $windowCache = [];

    public function __construct(private EmbeddingClient $embeddings, ?float $threshold = null, ?float $fuzzyThreshold = null)
    {
        $this->threshold = $threshold ?? (float) config('services.nlp.quote_similarity_threshold');
        $this->fuzzyThreshold = $fuzzyThreshold ?? (float) config('services.nlp.quote_fuzzy_threshold');
    }

    public function verify(string $quote, Answer $answer): QuoteMatch
    {
        $text = (string) $answer->transcript;
        $fragments = self::fragments($quote);
        $tokens = TextNormalizer::tokens($text);
        if ($fragments === [] || $tokens === []) {
            return new QuoteMatch(QuoteMatch::NONE);
        }

        $method = QuoteMatch::EXACT;
        $first = $last = null;
        $from = 0;
        foreach ($fragments as $fragment) {
            $needle = TextNormalizer::tokens($fragment);
            $at = TextNormalizer::find($tokens, $needle, $from);
            if ($at === null) {
                $method = null;
                break;
            }
            if (! str_contains($text, $fragment)) {
                $method = QuoteMatch::NORMALIZED;
            }
            $first ??= $at;
            $last = $at + count($needle) - 1;
            $from = $last + 1;
        }

        if ($method !== null) {
            [$start, $end] = $this->timing($answer, $tokens, $first, $last);

            return new QuoteMatch($method, null, null, $start, $end);
        }

        $quoteTokens = TextNormalizer::tokens(implode(' ', $fragments));
        [$score, $at, $len] = self::closestRun($quoteTokens, $tokens);
        $run = array_slice($tokens, $at, $len);
        // A spelling slip is forgivable, a changed number is not: in a long quote "delapan juta" -> "80 juta"
        // costs few characters but changes the evidence (salary, years of experience, team size).
        if ($score >= $this->fuzzyThreshold && self::numbers($quoteTokens) === self::numbers($run)) {
            [$start, $end] = $this->timing($answer, $tokens, $at, $at + $len - 1);

            return new QuoteMatch(QuoteMatch::FUZZY, round($score, 4), implode(' ', $run), $start, $end);
        }

        return $this->bySimilarity(implode(' ', $fragments), $answer, $tokens);
    }

    /**
     * Run of transcript tokens, about as long as the quote, with the highest character
     * similarity (1 - Levenshtein / longer length).
     *
     * @return array{0: float, 1: int, 2: int} score, start index, length
     */
    private static function closestRun(array $quoteTokens, array $tokens): array
    {
        $q = implode(' ', $quoteTokens);
        $n = count($quoteTokens);
        $best = [0.0, 0, 0];
        for ($len = max(1, $n - 2); $len <= $n + 2; $len++) {
            for ($i = 0, $last = count($tokens) - $len; $i <= $last; $i++) {
                $w = implode(' ', array_slice($tokens, $i, $len));
                $score = 1 - levenshtein($q, $w) / max(strlen($q), strlen($w));
                if ($score > $best[0]) {
                    $best = [$score, $i, $len];
                }
            }
        }

        return $best;
    }

    /** Digits and Indonesian number words, in order. @return list<string> */
    private static function numbers(array $tokens): array
    {
        $words = '/^(\d+|nol|satu|se|dua|tiga|empat|lima|enam|tujuh|delapan|sembilan|sepuluh|sebelas|belas|puluh|ratus|seratus|ribu|seribu|juta|miliar|triliun|setengah)$/u';

        return array_values(array_filter($tokens, fn ($t) => preg_match($words, $t)));
    }

    private function bySimilarity(string $quote, Answer $answer, array $tokens): QuoteMatch
    {
        [$windows, $vectors] = $this->windows((string) $answer->transcript);
        [$q] = $this->embeddings->embed([$quote]);
        $best = -1.0;
        $bestWindow = null;
        foreach ($vectors as $i => $v) {
            $score = EmbeddingClient::cosine($q, $v);
            if ($score > $best) {
                [$best, $bestWindow] = [$score, $windows[$i]];
            }
        }

        $method = $best >= $this->threshold ? QuoteMatch::SIMILAR : QuoteMatch::NONE;
        $start = $end = null;
        if ($method === QuoteMatch::SIMILAR) {
            $needle = TextNormalizer::tokens($bestWindow);
            $at = TextNormalizer::find($tokens, $needle);
            if ($at !== null) {
                [$start, $end] = $this->timing($answer, $tokens, $at, $at + count($needle) - 1);
            }
        }

        return new QuoteMatch($method, round($best, 4), $bestWindow, $start, $end);
    }

    /**
     * Overlapping word windows of the transcript and their embeddings, computed once
     * per transcript text: a scorecard checks many quotes against the same answer.
     *
     * @return array{0: list<string>, 1: list<list<float>>}
     */
    private function windows(string $text): array
    {
        $key = md5($text);
        if (! isset($this->windowCache[$key])) {
            $words = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY);
            $windows = [];
            foreach (self::WINDOWS as $size) {
                if (count($words) <= $size) {
                    $windows[] = implode(' ', $words);
                    continue;
                }
                for ($i = 0; $i + $size <= count($words); $i += self::STRIDE) {
                    $windows[] = implode(' ', array_slice($words, $i, $size));
                }
                $windows[] = implode(' ', array_slice($words, -$size));   // the tail the stride may skip
            }
            $windows = array_values(array_unique($windows));
            $this->windowCache[$key] = [$windows, $this->embeddings->embed($windows)];
        }

        return $this->windowCache[$key];
    }

    /**
     * Audio position of a matched token range, from the final segments the raw
     * transcript was built from. Null when the transcript was edited by hand or the
     * segments no longer spell out the same words: better no timestamp than a wrong one.
     *
     * @return array{0: ?int, 1: ?int}
     */
    private function timing(Answer $answer, array $tokens, int $first, int $last): array
    {
        if ($answer->transcript_edited !== null) {
            return [null, null];
        }

        $segments = $answer->transcriptSegments()
            ->where('is_final', true)
            ->where(fn ($q) => $q->whereNull('speaker')->orWhere('speaker', 'candidate'))
            ->orderBy('id')
            ->get()
            ->values();

        $segmentTokens = [];
        $owner = [];
        foreach ($segments as $i => $segment) {
            foreach (TextNormalizer::tokens((string) $segment->text) as $token) {
                $segmentTokens[] = $token;
                $owner[] = $i;
            }
        }
        if ($segmentTokens !== $tokens) {
            return [null, null];
        }

        return [$segments[$owner[$first]]->start_ms, $segments[$owner[$last]]->end_ms];
    }

    /**
     * Quote split on ellipses, with wrapping quotation marks removed.
     *
     * @return list<string>
     */
    private static function fragments(string $quote): array
    {
        $parts = preg_split('/\s*(?:\.{3}|…)\s*/u', $quote);
        $parts = array_map(fn ($p) => trim($p, " \t\n\r\"'“”‘’«»"), $parts);

        return array_values(array_filter($parts, fn ($p) => TextNormalizer::tokens($p) !== []));
    }
}
