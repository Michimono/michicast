<?php

use App\Support\RecordingDestinationProvider;
use App\Support\RssSourceProvider;
use App\Support\TelegramDestinationProvider;
use App\Support\TwitterSourceProvider;
use App\Support\WebPageSourceProvider;

return [

    /*
    |--------------------------------------------------------------------------
    | Parent Brand
    |--------------------------------------------------------------------------
    |
    | The product name and public URL come from "app.name" and "app.url", so
    | APP_NAME and APP_URL stay the single source for both. This value names
    | the brand the product is published under.
    |
    */

    'brand' => env('BRAND_NAME', 'Michimono'),

    /*
    |--------------------------------------------------------------------------
    | Support Contact
    |--------------------------------------------------------------------------
    |
    | The address users are pointed at for help. It follows the deployed
    | domain, so it is read from the environment rather than written into
    | views or mail templates.
    |
    */

    'support_email' => env('SUPPORT_EMAIL', 'support@michicast.com'),

    /*
    |--------------------------------------------------------------------------
    | Due Project Dispatch
    |--------------------------------------------------------------------------
    |
    | The scheduler ticks every minute and runs due projects inline. The time
    | budget leaves headroom before the next cron tick. A stale claim is
    | reclaimed after this many seconds so a crashed run does not stick.
    |
    */

    'dispatch' => [
        'time_budget' => (int) env('DISPATCH_TIME_BUDGET', 50),
        'stale_claim_after' => (int) env('DISPATCH_STALE_CLAIM_AFTER', 120),
    ],

    /*
    |--------------------------------------------------------------------------
    | Delivery Retry
    |--------------------------------------------------------------------------
    |
    | Transient destination failures are retried inline within the dispatch
    | time budget. Prefer the provider's retry-after hint when present; the
    | backoff below is the fallback wait between attempts.
    |
    */

    'retry' => [
        'max_attempts' => (int) env('DELIVERY_RETRY_MAX_ATTEMPTS', 3),
        'backoff_seconds' => (int) env('DELIVERY_RETRY_BACKOFF_SECONDS', 2),
    ],

    /*
    |--------------------------------------------------------------------------
    | Failure Auto-Pause
    |--------------------------------------------------------------------------
    |
    | A broadcast that fails this many times in a row is disabled with a
    | recorded reason. The default sits above a two-hour outage at one
    | dispatch tick per minute so a temporary provider outage does not pause
    | a healthy route.
    |
    */

    'failure' => [
        'broadcast_pause_after' => (int) env('BROADCAST_PAUSE_AFTER_FAILURES', 150),
    ],

    /*
    |--------------------------------------------------------------------------
    | Broadcast Preview
    |--------------------------------------------------------------------------
    |
    | Preview fetches live source items, applies filters and transforms, and
    | shows the most recent few without publishing. Keep the window small so
    | a preview request stays cheap.
    |
    */

    'preview' => [
        'item_limit' => (int) env('BROADCAST_PREVIEW_ITEM_LIMIT', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Source Providers
    |--------------------------------------------------------------------------
    |
    | Source types are resolved through the registry by the key used here, so
    | adding a provider is a line in this list rather than a change to domain
    | code. Tests may register additional providers at runtime.
    |
    */

    'sources' => [
        'rss' => RssSourceProvider::class,
        'twitter' => TwitterSourceProvider::class,
        'web_page' => WebPageSourceProvider::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Destination Providers
    |--------------------------------------------------------------------------
    |
    | Destination types are resolved the same way sources are. The recording
    | destination keeps what it was asked to publish instead of sending it,
    | useful in tests. Telegram is the first live destination.
    |
    */

    'destinations' => [
        'recording' => RecordingDestinationProvider::class,
        'telegram' => TelegramDestinationProvider::class,
    ],

];
