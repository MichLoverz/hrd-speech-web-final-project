<?php

namespace App\Services\Llm;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * One client for every LLM provider HireNote uses (ADR-004, ADR-009): Gemini's
 * OpenAI-compatible endpoint (development), OpenRouter (experiments, real data)
 * and Ollama (local fallback) all speak the OpenAI chat-completions format, so
 * switching provider is an .env change, not a code change.
 *
 * Provider-specific extras are the only branches: OpenRouter's privacy routing
 * and cost accounting, and the name of the reasoning parameter.
 */
class ChatClient
{
    public function __construct(private array $config) {}

    public static function fromConfig(): self
    {
        return new self(config('services.llm'));
    }

    public function model(): string
    {
        return $this->config['model'];
    }

    public function provider(): string
    {
        return $this->config['provider'];
    }

    /** Free tiers may let humans read inputs; such a provider must never see participant data. */
    public function isTestDataOnly(): bool
    {
        return (bool) $this->config['test_data_only'];
    }

    /**
     * @param  list<array{role: string, content: string}>  $messages
     * @param  array|null  $schema  JSON Schema the reply must follow (structured output)
     */
    public function complete(array $messages, ?array $schema = null, string $schemaName = 'result'): ChatResponse
    {
        if (blank($this->config['api_key']) && $this->provider() !== 'ollama') {
            throw new LlmUnavailable('LLM_API_KEY is not set in .env');
        }

        $body = [
            'model' => $this->model(),
            'messages' => $messages,
            'temperature' => $this->config['temperature'],
        ];
        if ($schema !== null) {
            $body['response_format'] = ['type' => 'json_schema', 'json_schema' => ['name' => $schemaName, 'schema' => $schema, 'strict' => true]];
        }
        if (filled($this->config['reasoning_effort'])) {
            // OpenRouter normalises reasoning across providers under its own key; the others take OpenAI's.
            if ($this->provider() === 'openrouter') {
                $body['reasoning'] = ['effort' => $this->config['reasoning_effort']];
            } else {
                $body['reasoning_effort'] = $this->config['reasoning_effort'];
            }
        }
        if ($this->provider() === 'openrouter') {
            // Never route to a provider that stores or trains on prompts: transcripts are personal data.
            $body['provider'] = ['data_collection' => 'deny'];
            $body['usage'] = ['include' => true];   // ask for the billed cost in the response
        }

        $started = microtime(true);
        try {
            // Overload (503 "high demand"), rate limits and dropped connections are routine on the
            // free tier and pass within seconds; a 4xx like a bad key or bad schema is not retried.
            $response = Http::withToken((string) $this->config['api_key'])
                ->timeout($this->config['timeout'])
                ->acceptJson()
                ->retry(
                    (int) ($this->config['retries'] ?? 3),
                    fn (int $attempt) => (int) ($this->config['retry_base_ms'] ?? 2000) * 2 ** ($attempt - 1),
                    fn (\Throwable $e) => $e instanceof ConnectionException
                        || ($e instanceof RequestException && in_array($e->response->status(), [429, 500, 502, 503, 504], true)),
                    throw: false,
                )
                ->post(rtrim($this->config['base_url'], '/').'/chat/completions', $body)
                ->throw();
        } catch (ConnectionException|RequestException $e) {
            throw new LlmUnavailable('LLM request failed: '.$e->getMessage(), previous: $e);
        }

        $usage = $response->json('usage') ?? [];

        return new ChatResponse(
            content: (string) $response->json('choices.0.message.content'),
            model: $response->json('model') ?? $this->model(),
            provider: $this->provider(),
            promptTokens: $usage['prompt_tokens'] ?? null,
            completionTokens: $usage['completion_tokens'] ?? null,
            reasoningTokens: $usage['completion_tokens_details']['reasoning_tokens'] ?? null,
            costUsd: isset($usage['cost']) ? (float) $usage['cost'] : null,
            latencyMs: (int) round((microtime(true) - $started) * 1000),
            finishReason: $response->json('choices.0.finish_reason'),
        );
    }
}
