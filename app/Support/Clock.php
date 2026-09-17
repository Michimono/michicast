<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Wall-clock helpers for execution. When Carbon is frozen for tests, sleep
 * advances the fake clock instead of blocking the process.
 */
class Clock
{
    public function sleep(int $seconds): void
    {
        if ($seconds <= 0) {
            return;
        }

        if (Carbon::hasTestNow()) {
            Carbon::setTestNow(now()->addSeconds($seconds));

            return;
        }

        sleep($seconds);
    }
}
