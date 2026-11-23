<?php

namespace App\Jobs;

use App\Support\ExecutingProjectRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendScheduledDelivery implements ShouldQueue
{
    use Queueable;

    /**
     * One delivery, including its inline retries, stays inside the dispatch
     * budget. A lost job is sent by deliveries:send-due.
     */
    public int $timeout = 90;

    public int $tries = 1;

    public function __construct(public int $deliveryId)
    {
        $this->onQueue('default');
    }

    public function handle(ExecutingProjectRunner $runner): void
    {
        $runner->deliverScheduled($this->deliveryId);
    }
}
