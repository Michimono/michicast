<?php

namespace App\Console\Commands;

use App\Support\DispatchDueProjects;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('projects:dispatch')]
#[Description('Claim due active projects and run them inline or dispatch RunProject jobs')]
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
