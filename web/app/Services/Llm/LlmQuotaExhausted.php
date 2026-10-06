<?php

namespace App\Services\Llm;

/** A quota that resets in hours (e.g. Gemini free tier, 20 requests/day/model). Retrying soon is pointless. */
class LlmQuotaExhausted extends LlmUnavailable {}
