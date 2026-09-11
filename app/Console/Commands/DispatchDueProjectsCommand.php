<?php

namespace App\Console\Commands;

use App\Support\DispatchDueProjects;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('projects:dispatch')]
#[Description('Claim and run due active projects inline')]
class DispatchDueProjectsCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(DispatchDueProjects $dispatcher): int
    {
        $dispatcher->handle();

        return self::SUCCESS;
    }
}
