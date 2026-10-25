@props(['title' => null])

{{--
    Full-height workspace shell for the pipeline editor. The canvas owns the
    viewport, so there is no page scroll, no contextual rail and no card
    around the content: only a slim bar with the application lockup on the
    left (the way back) and the broadcast's own controls on the right.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ $title ? $title.' - '.config('app.name') : config('app.name') }}</title>

        <link rel="icon" href="/images/brand/logo-day-mark.svg" type="image/svg+xml">

        @inject('fontStylesheet', App\Support\FontStylesheet::class)
        {!! $fontStylesheet->render() !!}
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="h-full overflow-hidden bg-paper font-sans text-ink antialiased">
        <div class="flex h-full flex-col">
            <header class="flex h-14 shrink-0 items-center gap-3 border-b border-line bg-paper-raised px-3 sm:gap-4 sm:px-4">
                {{ $bar }}
            </header>

            <main class="relative min-h-0 flex-1">
                @if (session('success') || session('error'))
                    <div class="pointer-events-none absolute inset-x-0 top-3 z-40 flex justify-center px-4" data-editor-flash>
                        @if (session('success'))
                            <p role="status" class="pointer-events-auto rounded-md border border-state-ok/30 bg-paper-raised px-4 py-2 text-sm text-state-ok shadow-sm">
                                {{ session('success') }}
                            </p>
                        @endif

                        @if (session('error'))
                            <p role="alert" class="pointer-events-auto rounded-md border border-state-fail/30 bg-paper-raised px-4 py-2 text-sm text-state-fail shadow-sm">
                                {{ session('error') }}
                            </p>
                        @endif
                    </div>
                @endif

                {{ $slot }}
            </main>
        </div>
    </body>
</html>
