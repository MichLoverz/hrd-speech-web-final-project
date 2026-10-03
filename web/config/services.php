<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    // HireNote ASR / NLP service (services/, FastAPI). The browser talks to the
    // WebSocket directly; Laravel only calls the HTTP endpoints.
    'asr' => [
        'http' => env('ASR_HTTP_URL', 'http://127.0.0.1:8765'),
        'ws' => env('ASR_WS_URL', 'ws://127.0.0.1:8765/asr/stream'),
        // Send the question text as Whisper's initial_prompt (contextual biasing). Switchable for experiments.
        'use_prompt' => (bool) env('ASR_USE_PROMPT', true),
        'timeout' => (int) env('ASR_HTTP_TIMEOUT', 300),
        // Phrases the interviewer can say to move to the next question (continuous mode).
        // Matched fuzzily on the interviewer's transcript; the question text itself is matched too.
        'trigger_phrases' => array_values(array_filter(array_map('trim', explode('|', env('ASR_TRIGGER_PHRASES',
            'lanjut ke pertanyaan berikutnya|pertanyaan berikutnya|pertanyaan selanjutnya|lanjut ke pertanyaan selanjutnya|next question|pertanyaan terakhir'))))),
    ],

    // Scorecard NLP (ADR-004, ADR-008). Same Python process as the ASR by default.
    'nlp' => [
        'http' => env('NLP_HTTP_URL', env('ASR_HTTP_URL', 'http://127.0.0.1:8765')),
        'timeout' => (int) env('NLP_HTTP_TIMEOUT', 120),
        // null = the service default (HIRENOTE_EMBED_MODEL, MiniLM-L12 multilingual). Switchable for the sub-experiment.
        'embed_model' => env('NLP_EMBED_MODEL'),
        // PROVISIONAL, not calibrated: both are calibrated on real LLM quotes once they exist (ADR-008).
        'quote_similarity_threshold' => (float) env('NLP_QUOTE_SIMILARITY_THRESHOLD', 0.80),
        'quote_fuzzy_threshold' => (float) env('NLP_QUOTE_FUZZY_THRESHOLD', 0.85),
        'retrieval_top_k' => (int) env('NLP_RETRIEVAL_TOP_K', 5),
    ],

    // LLM for the draft scorecard (ADR-009). Any OpenAI-compatible endpoint: Gemini (development),
    // OpenRouter (experiments and real interviews) or Ollama (local fallback).
    'llm' => [
        'provider' => env('LLM_PROVIDER', 'gemini'),   // gemini | openrouter | ollama
        'base_url' => env('LLM_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta/openai'),
        'api_key' => env('LLM_API_KEY'),
        'model' => env('LLM_MODEL', 'gemini-3.5-flash'),
        // Gemini 3 cannot switch reasoning off, only down; empty = provider default.
        'reasoning_effort' => env('LLM_REASONING_EFFORT', 'low'),
        'temperature' => (float) env('LLM_TEMPERATURE', 0.2),
        'timeout' => (int) env('LLM_TIMEOUT', 180),
        // The Gemini free tier lets Google staff read inputs: true = only for read scripts and made-up
        // test interviews, never for participants. Set false only for a paid, no-training provider.
        'test_data_only' => (bool) env('LLM_TEST_DATA_ONLY', true),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
