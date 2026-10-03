<?php

namespace App\Services\Llm;

use RuntimeException;

/** No usable reply from the LLM provider (no key, network, quota, server error). Callers retry later. */
class LlmUnavailable extends RuntimeException {}
