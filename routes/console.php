<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('projects:dispatch')
    ->everyMinute()
    ->withoutOverlapping(2);

Schedule::command('horizon:snapshot')->everyFiveMinutes();

Schedule::command('queue:prune-failed --hours=48')->daily();

// Usage metering periods are UTC calendar days; keep 90 days of history (#160).
Schedule::command('usage:prune')->daily();
