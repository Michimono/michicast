<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Boost Browser Logs Watcher
    |--------------------------------------------------------------------------
    |
    | Boost injects <script id="browser-logger-active"> before </head> when
    | this is on (local, or whenever APP_DEBUG is true). That inline script is
    | not application behavior, and a strict CSP blocks it. The default is off
    | so the tag is never emitted. Setting BOOST_BROWSER_LOGS_WATCHER=true
    | brings the inline script back, and the CSP will block it again.
    |
    */

    'browser_logs_watcher' => filter_var(env('BOOST_BROWSER_LOGS_WATCHER', false), FILTER_VALIDATE_BOOL),

];
