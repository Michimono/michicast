<?php

namespace App\Console\Commands;

use App\Support\ExecutingProjectRunner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('deliveries:send-due')]
#[Description('Send destination deliveries whose scheduled time has arrived')]
class SendDueDeliveriesCommand extends Command
{
    public function handle(ExecutingProjectRunner $runner): int
    {
        $runner->deliverDue();

        return self::SUCCESS;
    }
}
