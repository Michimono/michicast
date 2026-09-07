@props(['title' => null])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ $title ? $title.' - '.config('app.name') : config('app.name') }}</title>

        @fonts
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="bg-zinc-50 font-sans text-zinc-900 antialiased">
        <div class="flex min-h-screen flex-col">
            <header class="border-b border-zinc-200 bg-white">
                <div class="mx-auto flex w-full max-w-5xl items-center justify-between gap-6 px-6 py-4">
                    <a class="text-lg font-semibold tracking-tight" href="{{ route('home') }}">
                        {{ config('app.name') }}
                    </a>

                    <nav class="flex items-center gap-4 text-sm">
                        {{ $nav ?? '' }}

                        @auth
                            <a class="hover:underline" href="{{ route('dashboard') }}">Dashboard</a>

                            <form method="POST" action="{{ route('session.destroy') }}">
                                @csrf
                                @method('DELETE')

                                <button class="hover:underline" type="submit">Log out</button>
                            </form>
                        @else
                            <a class="hover:underline" href="{{ route('session.create') }}">Log in</a>
                            <a class="hover:underline" href="{{ route('users.create') }}">Register</a>
                        @endauth
                    </nav>
                </div>
            </header>

            <main class="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-6 px-6 py-10">
                @if (session('success'))
                    <p role="status" class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
                        {{ session('success') }}
                    </p>
                @endif

                @if (session('error'))
                    <p role="alert" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900">
                        {{ session('error') }}
                    </p>
                @endif

                {{ $slot }}
            </main>

            <footer class="border-t border-zinc-200 bg-white">
                <div class="mx-auto flex w-full max-w-5xl flex-col gap-1 px-6 py-6 text-sm text-zinc-600 sm:flex-row sm:items-center sm:justify-between">
                    <p>A {{ config('michicast.brand') }} product.</p>

                    <a class="underline hover:no-underline" href="mailto:{{ config('michicast.support_email') }}">
                        {{ config('michicast.support_email') }}
                    </a>
                </div>
            </footer>
        </div>
    </body>
</html>
