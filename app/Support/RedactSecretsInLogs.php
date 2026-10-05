<?php

namespace App\Support;

use Illuminate\Log\Logger;
use Monolog\Handler\FormattableHandlerInterface;
use Monolog\Logger as Monolog;

/**
 * A logging channel tap that routes every handler's output through the
 * shared secret redactor.
 */
class RedactSecretsInLogs
{
    public function __invoke(Logger $logger): void
    {
        $monolog = $logger->getLogger();

        if (! $monolog instanceof Monolog) {
            return;
        }

        foreach ($monolog->getHandlers() as $handler) {
            if (! $handler instanceof FormattableHandlerInterface) {
                continue;
            }

            $formatter = $handler->getFormatter();

            if (! $formatter instanceof RedactingLogFormatter) {
                $handler->setFormatter(new RedactingLogFormatter($formatter));
            }
        }
    }
}
