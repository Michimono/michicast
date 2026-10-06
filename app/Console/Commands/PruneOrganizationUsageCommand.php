<?php

namespace App\Console\Commands;

use App\Support\QuotaMeter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('usage:prune {--days=90 : Delete usage rows older than this many days}')]
#[Description('Prune organization usage rows older than the retention window')]
class PruneOrganizationUsageCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(QuotaMeter $meter): int
    {
        $days = max(1, (int) $this->option('days'));
        $deleted = $meter->pruneOlderThan($days);

        $this->info("Deleted {$deleted} organization usage row(s) older than {$days} day(s).");

        return self::SUCCESS;
    }
}
