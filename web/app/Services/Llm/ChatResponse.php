<?php

namespace App\Services\Llm;

/** One LLM reply plus what it cost, kept for analysis_results and the cost-per-interview figure. */
final class ChatResponse
{
    public function __construct(
        public readonly string $content,
        public readonly string $model,
        public readonly string $provider,
        public readonly ?int $promptTokens,
        public readonly ?int $completionTokens,
        public readonly ?int $reasoningTokens,   // part of completionTokens on providers that report it
        public readonly ?float $costUsd,         // only OpenRouter reports the billed amount
        public readonly int $latencyMs,
        public readonly ?string $finishReason,
    ) {}

    /** The reply parsed as JSON, or null if the model did not return valid JSON. */
    public function json(): ?array
    {
        $data = json_decode($this->content, true);

        return is_array($data) ? $data : null;
    }
}
