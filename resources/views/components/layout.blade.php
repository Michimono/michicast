@props(['title' => null])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ $title ? $title.' - '.config('app.name') : config('app.name') }}</title>

        <link rel="icon" href="/images/brand/logo-day-mark.svg" type="image/svg+xml">

        @fonts
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="bg-paper font-sans text-ink antialiased">
        <div class="flex min-h-screen flex-col">
            <header class="border-b border-line bg-paper-raised">
                <div class="mx-auto flex w-full max-w-5xl items-center justify-between gap-6 px-6 py-4">
                    <a class="flex items-center gap-2.5" href="{{ route('home') }}">
                        <x-brand.mark variant="face" class="h-6" />

                        <span class="font-display text-xl tracking-tight">{{ config('app.name') }}</span>
                    </a>

                    <nav class="flex items-center gap-5">
                        {{ $nav ?? '' }}

                        @auth
                            <x-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')">Dashboard</x-nav-link>
                            <x-nav-link :href="route('profile.edit')" :active="request()->routeIs('profile.*')">Profile</x-nav-link>

                            <form method="POST" action="{{ route('session.destroy') }}">
                                @csrf
                                @method('DELETE')

                                <button class="text-sm text-muted transition-colors hover:text-ink" type="submit">
                                    Log out
                                </button>
                            </form>
                        @else
                            <x-nav-link :href="route('session.create')" :active="request()->routeIs('session.create')">Log in</x-nav-link>
                            <x-nav-link :href="route('users.create')" :active="request()->routeIs('users.create')">Register</x-nav-link>
                        @endauth
                    </nav>
                </div>
            </header>

            <main class="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-6 px-6 py-10">
                @if (session('success'))
                    <p role="status" class="rounded-md border border-state-ok/30 bg-state-ok/5 px-4 py-3 text-sm text-state-ok">
                        {{ session('success') }}
                    </p>
                @endif

                @if (session('error'))
                    <p role="alert" class="rounded-md border border-state-fail/30 bg-state-fail/5 px-4 py-3 text-sm text-state-fail">
                        {{ session('error') }}
                    </p>
                @endif

                {{ $slot }}
            </main>

            <footer class="border-t border-line bg-paper-raised">
                <div class="mx-auto flex w-full max-w-5xl flex-col gap-1 px-6 py-6 text-sm text-muted sm:flex-row sm:items-center sm:justify-between">
                    <p>A {{ config('michicast.brand') }} product.</p>

                    <a class="underline hover:no-underline" href="mailto:{{ config('michicast.support_email') }}">
                        {{ config('michicast.support_email') }}
                    </a>
                </div>
            </footer>
        </div>
    </body>
</html>
