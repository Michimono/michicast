@props([
    'title' => null,
    'description' => null,
    'cta',
    'sections' => [],
    'logIn' => false,
    'documentationUrl' => null,
    'contactEmail' => null,
])

{{--
    The public website's shell. It is deliberately not <x-layout>: no account
    navigation, no contextual rail and no application column, because a
    visitor is reading about the product, not working in it. The lockup, the
    fonts and the tokens are the only things the two shells share.

    $cta is the one next step this visitor can take (register, log in, or open
    the app), decided once by the page so the header, hero and footer agree.
    $sections are the page's own anchors, as a list of href and label, so the
    navigation never links to a section the page did not render.
--}}
@php
    $focus = 'rounded-sm focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-accent';
    $quiet = 'text-sm text-muted transition-colors hover:text-ink '.$focus;
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth motion-reduce:scroll-auto">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ $title ? $title.' - '.config('app.name') : config('app.name') }}</title>

        @if ($description)
            <meta name="description" content="{{ $description }}">
        @endif

        <link rel="icon" href="/images/brand/logo-day-mark.svg" type="image/svg+xml">

        @inject('fontStylesheet', App\Support\FontStylesheet::class)
        {!! $fontStylesheet->render() !!}
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="overflow-x-clip bg-paper font-sans text-ink antialiased">
        <a
            href="#content"
            class="sr-only rounded-md bg-ink px-4 py-2 text-sm font-medium text-ink-contrast focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-50"
        >Skip to content</a>

        <header class="sticky top-0 z-40 bg-paper/90 backdrop-blur-md">
            <div class="mx-auto flex h-16 w-full max-w-7xl items-center gap-4 px-5 sm:gap-6 sm:px-8">
                <a class="flex min-w-0 items-center gap-2.5 {{ $focus }}" href="{{ route('home') }}">
                    <x-brand.mark variant="face" class="h-6 shrink-0" />

                    <span class="truncate font-display text-[1.375rem] tracking-tight">{{ config('app.name') }}</span>
                </a>

                <nav class="ml-6 hidden items-center gap-7 lg:flex" aria-label="Site">
                    @foreach ($sections as $section)
                        <a class="{{ $quiet }}" href="{{ $section['href'] }}">{{ $section['label'] }}</a>
                    @endforeach

                    @if ($documentationUrl)
                        <a class="{{ $quiet }}" href="{{ $documentationUrl }}">Docs</a>
                    @endif
                </nav>

                <div class="ml-auto flex shrink-0 items-center gap-3 sm:gap-5">
                    @if ($contactEmail)
                        <a class="hidden sm:inline {{ $quiet }}" href="mailto:{{ $contactEmail }}">Contact</a>
                    @endif

                    @if ($logIn)
                        <a class="{{ $quiet }}" href="{{ route('session.create') }}">Log in</a>
                    @endif

                    <x-button :href="$cta['href']" :paw="false">{{ $cta['label'] }}</x-button>
                </div>
            </div>
        </header>

        @if (session('success') || session('error'))
            <div class="mx-auto flex w-full max-w-7xl flex-col gap-2 px-5 pt-4 sm:px-8">
                @if (session('success'))
                    <p role="status" class="w-fit rounded-md border border-state-ok/30 bg-paper-raised px-4 py-2.5 text-sm text-state-ok shadow-float">
                        {{ session('success') }}
                    </p>
                @endif

                @if (session('error'))
                    <p role="alert" class="w-fit rounded-md border border-state-fail/30 bg-paper-raised px-4 py-2.5 text-sm text-state-fail shadow-float">
                        {{ session('error') }}
                    </p>
                @endif
            </div>
        @endif

        <main id="content">
            {{ $slot }}
        </main>

        <footer>
            <div class="mx-auto grid w-full max-w-7xl gap-12 px-5 pt-20 pb-10 sm:px-8 lg:grid-cols-12 lg:pt-28">
                <div class="flex flex-col items-start gap-4 lg:col-span-5">
                    <a class="flex items-center gap-2.5 {{ $focus }}" href="{{ route('home') }}">
                        <x-brand.mark variant="face" class="h-6 shrink-0" />

                        <span class="font-display text-[1.375rem] tracking-tight">{{ config('app.name') }}</span>
                    </a>

                    <p class="max-w-xs text-sm leading-6 text-muted">
                        Information, routed from where it appears to where it is read. A {{ config('michicast.brand') }} product.
                    </p>
                </div>

                <nav class="grid grid-cols-2 gap-x-8 gap-y-10 sm:grid-cols-3 lg:col-span-7" aria-label="Footer">
                    <div class="flex flex-col items-start gap-3">
                        <p class="font-mono text-[0.6875rem] tracking-[0.14em] text-muted uppercase">Product</p>

                        @foreach ($sections as $section)
                            <a class="text-sm text-ink underline-offset-4 hover:underline {{ $focus }}" href="{{ $section['href'] }}">{{ $section['label'] }}</a>
                        @endforeach
                    </div>

                    @if ($documentationUrl || $contactEmail)
                        <div class="flex flex-col items-start gap-3">
                            <p class="font-mono text-[0.6875rem] tracking-[0.14em] text-muted uppercase">Support</p>

                            @if ($documentationUrl)
                                <a class="text-sm text-ink underline-offset-4 hover:underline {{ $focus }}" href="{{ $documentationUrl }}">Documentation</a>
                            @endif

                            @if ($contactEmail)
                                <a class="text-sm text-ink underline-offset-4 hover:underline {{ $focus }}" href="mailto:{{ $contactEmail }}">{{ $contactEmail }}</a>
                            @endif
                        </div>
                    @endif

                    <div class="flex flex-col items-start gap-3">
                        <p class="font-mono text-[0.6875rem] tracking-[0.14em] text-muted uppercase">Account</p>

                        <a class="text-sm text-ink underline-offset-4 hover:underline {{ $focus }}" href="{{ $cta['href'] }}">{{ $cta['label'] }}</a>

                        @if ($logIn)
                            <a class="text-sm text-ink underline-offset-4 hover:underline {{ $focus }}" href="{{ route('session.create') }}">Log in</a>
                        @endif
                    </div>
                </nav>

                <p class="font-mono text-[0.6875rem] text-muted lg:col-span-12">&copy; {{ now()->year }} {{ config('michicast.brand') }}. All rights reserved.</p>
            </div>
        </footer>
    </body>
</html>
