<?php

namespace App\Support;

use App\Jobs\SendScheduledDelivery;
use App\Models\ContentDelivery;

/**
 * Hands a paced delivery to Horizon when a real queue will honour the delay.
 * The sync driver runs a delayed job immediately, which would burst the
 * destination, so that path waits for the scheduler instead.
 */
class ScheduledDeliveryQueue
{
    public function push(ContentDelivery $delivery): void
    {
        $when = $delivery->scheduled_for;

        if ($when === null || config('queue.default') === 'sync') {
            return;
        }

        SendScheduledDelivery::dispatch((int) $delivery->getKey())->delay($when);
    }
}
