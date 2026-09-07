<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ config('app.name') }}</title>

        @fonts
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="flex min-h-screen flex-col items-center justify-center gap-2 p-6 text-center">
        <h1 class="text-2xl font-semibold">{{ config('app.name') }}</h1>
        <p>A {{ config('michicast.brand') }} product.</p>
        <p>
            <a class="underline" href="mailto:{{ config('michicast.support_email') }}">
                {{ config('michicast.support_email') }}
            </a>
        </p>
    </body>
</html>
