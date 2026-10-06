<?php

namespace App\Services\Scorecard;

use RuntimeException;

/** The session may not be sent to the configured LLM (no transcript, consent, or test-data-only provider). Not retried. */
class ScorecardNotAllowed extends RuntimeException {}
