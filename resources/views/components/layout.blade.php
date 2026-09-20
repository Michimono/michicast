@props(['title' => null, 'footer' => true, 'centered' => false])

@inject('currentOrganization', App\Support\CurrentOrganization::class)

@php
    $organization = $currentOrganization->isResolved() ? $currentOrganization->organization() : null;
    $settings = request()->routeIs('profile.*');
    $rail = $organization !== null || $settings;
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['scroll-smooth' => $settings])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ $title ? $title.' - '.config('app.name') : config('app.name') }}</title>

        <link rel="icon" href="/images/brand/logo-day-mark.svg" type="image/svg+xml">

        @fonts
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="bg-paper font-sans text-ink antialiased">
        @if ($organization)
            <x-organization-sidebar :organization="$organization" />
        @elseif ($settings)
            <x-settings-sidebar />
        @endif

        <div class="flex min-h-screen flex-col">
            {{-- Account chrome. These links are the same whichever organization is open. --}}
            <header class="border-b border-line bg-paper-raised">
                <div class="mx-auto flex w-full max-w-7xl items-center justify-between gap-6 px-6 py-4">
                    <div class="flex items-center gap-3">
                        @if ($organization)
                            <button
                                class="rounded-md p-1.5 text-muted transition-colors hover:text-ink lg:hidden"
                                type="button"
                                data-sidebar-toggle
                                aria-controls="organization-sidebar"
                                aria-expanded="false"
                            >
                                <x-icons.menu />

                                <span class="sr-only">Organization menu</span>
                            </button>
                        @elseif ($settings)
                            <button
                                class="rounded-md p-1.5 text-muted transition-colors hover:text-ink lg:hidden"
                                type="button"
                                data-sidebar-toggle
                                aria-controls="settings-sidebar"
                                aria-expanded="false"
                            >
                                <x-icons.menu />

                                <span class="sr-only">Settings menu</span>
                            </button>
                        @endif

                        @unless ($organization)
                            <a class="flex items-center gap-2.5" href="{{ route('home') }}">
                                <x-brand.mark variant="face" class="h-6" />

                                <span class="font-display text-xl tracking-tight">{{ config('app.name') }}</span>
                            </a>
                        @endunless
                    </div>

                    <nav class="flex items-center gap-1" aria-label="Account">
                        {{ $nav ?? '' }}

                        @auth
                            <x-nav-link
                                :href="route('organizations.index')"
                                :active="request()->routeIs('organizations.index', 'organizations.create')"
                            >Organizations</x-nav-link>

                            <x-nav-link :href="route('profile.edit')" :active="request()->routeIs('profile.*')">Settings</x-nav-link>

                            <form method="POST" action="{{ route('session.destroy') }}">
                                @csrf
                                @method('DELETE')

                                <button
                                    class="rounded-md px-2.5 py-1 text-sm text-muted transition-colors hover:bg-paper hover:text-ink"
                                    type="submit"
                                >
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

            {{-- Only the page body yields to the rail; the header stays full-bleed. --}}
            <div @class(['flex flex-1 flex-col', 'lg:pl-24 xl:pl-64' => $rail])>
                <main @class([
                    'mx-auto flex w-full flex-1 flex-col px-6',
                    'max-w-lg justify-center gap-8 py-12' => $centered,
                    'max-w-5xl gap-6 py-10' => ! $centered,
                ])>
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
            </div>

            {{-- Guest marketing chrome only. Auth, register, and the signed-in app omit it. --}}
            @if ($footer && auth()->guest())
                <footer class="border-t border-line bg-paper-raised">
                    <div class="mx-auto flex w-full max-w-7xl flex-col gap-1 px-6 py-6 text-sm text-muted sm:flex-row sm:items-center sm:justify-between">
                        <p>A {{ config('michicast.brand') }} product.</p>

                        <a class="underline hover:no-underline" href="mailto:{{ config('michicast.support_email') }}">
                            {{ config('michicast.support_email') }}
                        </a>
                    </div>
                </footer>
            @endif
        </div>
    </body>
</html>
