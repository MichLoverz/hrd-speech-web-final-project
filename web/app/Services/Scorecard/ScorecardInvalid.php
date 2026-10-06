<?php

namespace App\Services\Scorecard;

use RuntimeException;

/** The model replied, but not with a usable scorecard. Worth one retry: models fail this randomly. */
class ScorecardInvalid extends RuntimeException {}
