<?php

namespace Tests\Feature;

use App\Services\Llm\ChatClient;
use App\Services\Llm\LlmQuotaExhausted;
use App\Services\Llm\LlmUnavailable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** The OpenAI-compatible LLM client: request shape per provider, usage parsing, failures. */
class Phase4LlmClientTest extends TestCase
{
    private function client(array $overrides = []): ChatClient
    {
        return new ChatClient(array_merge([
            'provider' => 'gemini',
            'base_url' => 'https://generativelanguage.googleapis.com/v1beta/openai/',
            'api_key' => 'test-key',
            'model' => 'gemini-3.5-flash',
            'reasoning_effort' => 'low',
            'temperature' => 0.2,
            'timeout' => 30,
            'retries' => 3,
            'retry_base_ms' => 0,
            'test_data_only' => true,
        ], $overrides));
    }

    private function reply(string $content, array $usage = []): array
    {
        return ['model' => 'gemini-3.5-flash', 'choices' => [['message' => ['content' => $content], 'finish_reason' => 'stop']],
            'usage' => $usage + ['prompt_tokens' => 900, 'completion_tokens' => 210]];
    }

    public function test_gemini_request_uses_bearer_key_schema_and_openai_reasoning_param(): void
    {
        Http::fake(['*' => Http::response($this->reply('{"ok":true}', ['completion_tokens_details' => ['reasoning_tokens' => 60]]))]);

        $r = $this->client()->complete([['role' => 'user', 'content' => 'halo']], ['type' => 'object'], 'scorecard');

        Http::assertSent(function (Request $req) {
            return $req->url() === 'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions'
                && $req->hasHeader('Authorization', 'Bearer test-key')
                && $req['model'] === 'gemini-3.5-flash'
                && $req['reasoning_effort'] === 'low'
                && $req['response_format']['json_schema']['name'] === 'scorecard'
                && ! isset($req['provider']);   // OpenRouter-only options never reach Gemini
        });
        $this->assertSame(['ok' => true], $r->json());
        $this->assertSame([900, 210, 60], [$r->promptTokens, $r->completionTokens, $r->reasoningTokens]);
        $this->assertNull($r->costUsd, 'Gemini does not report a billed amount');
        $this->assertTrue($this->client()->isTestDataOnly());
    }

    public function test_openrouter_request_denies_data_collection_and_reads_cost(): void
    {
        Http::fake(['*' => Http::response($this->reply('{}', ['cost' => 0.0381]))]);

        $r = $this->client(['provider' => 'openrouter', 'base_url' => 'https://openrouter.ai/api/v1', 'test_data_only' => false])
            ->complete([['role' => 'user', 'content' => 'halo']]);

        Http::assertSent(fn (Request $req) => $req->url() === 'https://openrouter.ai/api/v1/chat/completions'
            && $req['provider'] === ['data_collection' => 'deny']
            && $req['reasoning'] === ['effort' => 'low']
            && ! isset($req['reasoning_effort'])
            && ! isset($req['response_format']));
        $this->assertSame(0.0381, $r->costUsd);
    }

    public function test_overload_is_retried_but_a_client_error_is_not(): void
    {
        Http::fakeSequence()
            ->push(['error' => ['message' => 'This model is currently experiencing high demand.']], 503)
            ->push($this->reply('{"ok":true}'));

        $r = $this->client()->complete([['role' => 'user', 'content' => 'x']]);

        $this->assertSame(['ok' => true], $r->json());
        Http::assertSentCount(2);
    }

    public function test_exhausted_daily_quota_is_not_retried_and_says_when_it_resets(): void
    {
        // Shape of the real Gemini free-tier reply seen on 2026-10-07 (20 requests/day/model).
        Http::fake(['*' => Http::response([['error' => ['code' => 429, 'status' => 'RESOURCE_EXHAUSTED', 'details' => [
            ['@type' => 'type.googleapis.com/google.rpc.QuotaFailure', 'violations' => [['quotaId' => 'GenerateRequestsPerDayPerProjectPerModel-FreeTier', 'quotaValue' => '20']]],
            ['@type' => 'type.googleapis.com/google.rpc.RetryInfo', 'retryDelay' => '8889s'],
        ]]]], 429)]);

        try {
            $this->client()->complete([['role' => 'user', 'content' => 'x']]);
            $this->fail('expected LlmQuotaExhausted');
        } catch (LlmQuotaExhausted $e) {
            $this->assertStringContainsString('2 jam 28 menit', $e->getMessage());
        }
        Http::assertSentCount(1);
    }

    public function test_short_rate_limit_is_still_retried(): void
    {
        Http::fakeSequence()
            ->push(['error' => ['code' => 429, 'details' => [['@type' => 'type.googleapis.com/google.rpc.RetryInfo', 'retryDelay' => '5s']]]], 429)
            ->push($this->reply('{"ok":true}'));

        $this->assertSame(['ok' => true], $this->client()->complete([['role' => 'user', 'content' => 'x']])->json());
        Http::assertSentCount(2);
    }

    public function test_bad_request_fails_at_once(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'API key not valid']], 400)]);

        try {
            $this->client()->complete([['role' => 'user', 'content' => 'x']]);
            $this->fail('expected LlmUnavailable');
        } catch (LlmUnavailable) {
        }
        Http::assertSentCount(1);
    }

    public function test_invalid_json_reply_is_reported_as_null_not_an_exception(): void
    {
        Http::fake(['*' => Http::response($this->reply('Maaf, saya tidak bisa.'))]);

        $this->assertNull($this->client()->complete([['role' => 'user', 'content' => 'x']])->json());
    }

    public function test_missing_key_and_http_errors_raise_llm_unavailable(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'quota exceeded']], 429)]);

        try {
            $this->client(['api_key' => ''])->complete([['role' => 'user', 'content' => 'x']]);
            $this->fail('expected LlmUnavailable for a missing key');
        } catch (LlmUnavailable $e) {
            $this->assertStringContainsString('LLM_API_KEY', $e->getMessage());
        }
        Http::assertNothingSent();

        $this->expectException(LlmUnavailable::class);
        $this->client()->complete([['role' => 'user', 'content' => 'x']]);
    }
}
