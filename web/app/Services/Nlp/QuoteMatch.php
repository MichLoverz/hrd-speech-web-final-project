<?php

namespace App\Services\Nlp;

/** Outcome of checking one LLM quote against the answer transcript. */
final class QuoteMatch
{
    public const EXACT = 'exact';            // verbatim substring of the transcript
    public const NORMALIZED = 'normalized';  // same words; only case, punctuation or fillers differ
    public const FUZZY = 'fuzzy';            // nearly the same words, e.g. an ASR error "corrected": HRD must check
    public const SIMILAR = 'similar';        // paraphrase of a transcript passage: HRD must check
    public const NONE = 'none';              // nothing close enough: treat as hallucinated

    public function __construct(
        public readonly string $method,
        public readonly ?float $score = null,        // fuzzy: character similarity; similar / none: cosine
        public readonly ?string $matchedText = null, // the transcript text the quote was matched to
        public readonly ?int $startMs = null,
        public readonly ?int $endMs = null,
    ) {}

    /** Counts as evidence without a human look. A paraphrase never does. */
    public function isVerified(): bool
    {
        return in_array($this->method, [self::EXACT, self::NORMALIZED], true);
    }
}
