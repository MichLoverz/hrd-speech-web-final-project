<?php

namespace App\Console\Commands;

use App\Services\Llm\ChatClient;
use App\Services\Llm\LlmUnavailable;
use Illuminate\Console\Command;

/**
 * Checks the LLM settings in .env with one tiny request that contains no
 * personal data, so the API key never has to be shown to anyone.
 */
class LlmPing extends Command
{
    protected $signature = 'llm:ping';

    protected $description = 'Send one small structured-output request to the configured LLM and report model, tokens and latency';

    public function handle(): int
    {
        $client = ChatClient::fromConfig();
        $this->line("Provider: {$client->provider()} · model: {$client->model()}".($client->isTestDataOnly() ? ' · TEST DATA ONLY' : ''));

        $schema = [
            'type' => 'object',
            'properties' => ['jawaban' => ['type' => 'string'], 'angka' => ['type' => 'integer']],
            'required' => ['jawaban', 'angka'],
            'additionalProperties' => false,
        ];

        try {
            $r = $client->complete([
                ['role' => 'user', 'content' => 'Balas dalam JSON: "jawaban" berisi kata "siap", "angka" berisi hasil 2+3.'],
            ], $schema, 'ping');
        } catch (LlmUnavailable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $data = $r->json();
        $this->line('Reply: '.$r->content);
        $this->line(sprintf('Tokens: in %s · out %s (reasoning %s) · %d ms · finish %s%s',
            $r->promptTokens ?? '?', $r->completionTokens ?? '?', $r->reasoningTokens ?? '?', $r->latencyMs,
            $r->finishReason ?? '?', $r->costUsd !== null ? sprintf(' · $%.6f', $r->costUsd) : ''));

        if (($data['angka'] ?? null) !== 5) {
            $this->warn('Connected, but the reply did not follow the schema correctly.');

            return self::FAILURE;
        }
        $this->info('OK: key works and structured output follows the schema.');

        return self::SUCCESS;
    }
}
