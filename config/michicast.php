<?php

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

];
