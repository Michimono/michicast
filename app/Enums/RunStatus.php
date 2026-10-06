<?php

namespace App\Enums;

enum RunStatus: string
{
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Incomplete = 'incomplete';
    case QuotaExceeded = 'quota_exceeded';

    public function label(): string
    {
        return match ($this) {
            self::Running => 'Running',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
            self::Incomplete => 'Incomplete',
            self::QuotaExceeded => 'Out of quota',
        };
    }

    /**
     * Visual tone for Activity status chips: ok, fail, warn, skip, or muted.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Completed => 'ok',
            self::Failed => 'fail',
            self::QuotaExceeded => 'warn',
            self::Incomplete => 'skip',
            self::Running => 'muted',
        };
    }
}
