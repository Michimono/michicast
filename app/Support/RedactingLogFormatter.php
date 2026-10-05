<?php

namespace App\Support;

use Monolog\Formatter\FormatterInterface;
use Monolog\LogRecord;

/**
 * Wraps a channel's formatter and redacts the finished log line, so the
 * message, context, exception message and stack trace are all covered by
 * what actually reaches the log rather than by what each call site passes.
 */
class RedactingLogFormatter implements FormatterInterface
{
    public function __construct(private FormatterInterface $formatter) {}

    public function format(LogRecord $record): mixed
    {
        return $this->redact($this->formatter->format($record));
    }

    /**
     * @param  array<LogRecord>  $records
     */
    public function formatBatch(array $records): mixed
    {
        return $this->redact($this->formatter->formatBatch($records));
    }

    private function redact(mixed $formatted): mixed
    {
        if (is_string($formatted)) {
            return app(SecretRedactor::class)->redact($formatted);
        }

        if (is_array($formatted)) {
            return array_map(fn (mixed $value): mixed => $this->redact($value), $formatted);
        }

        return $formatted;
    }
}
