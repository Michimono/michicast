<?php

namespace App\Exceptions;

use App\Enums\QuotaMetric;
use App\Support\QuotaDecision;
use RuntimeException;

/**
 * Signals that a metered action was refused. Catch at the broadcast-run
 * boundary and map to RunStatus::QuotaExceeded. Never report() this.
 */
class QuotaExceededException extends RuntimeException
{
    public function __construct(
        public QuotaDecision $decision,
        public QuotaMetric $metric,
    ) {
        parent::__construct($decision->message($metric));
    }
}
