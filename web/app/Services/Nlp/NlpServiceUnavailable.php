<?php

namespace App\Services\Nlp;

use RuntimeException;

/** The Python service did not answer. Callers retry later rather than record a wrong result. */
class NlpServiceUnavailable extends RuntimeException {}
