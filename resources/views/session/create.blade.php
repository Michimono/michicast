<x-layout title="Log in" centered :footer="false">
    <div class="flex flex-col items-center gap-5">
        <x-brand.mark variant="cat" class="h-16" />

        <div class="flex flex-col items-center gap-3">
            <h1 class="font-display text-3xl tracking-tight">Log in to {{ config('app.name') }}</h1>

            <span class="house-fill h-0.5 w-16 rounded-full"></span>
        </div>
    </div>

    <x-card eared>
        <div class="flex flex-col gap-6">
            <form class="flex flex-col gap-5" method="POST" action="{{ route('session.store') }}">
                @csrf

                <x-input name="email" label="Email address" type="email" :value="$email" autocomplete="email" autofocus required />

                <div class="flex flex-col gap-1.5">
                    <x-input name="password" label="Password" type="password" autocomplete="current-password" required />

                    <a
                        class="self-end text-sm text-accent underline hover:no-underline"
                        href="{{ route('password-reset-links.create') }}"
                    >Forgot your password?</a>
                </div>

                <x-button class="w-full">Log in</x-button>
            </form>

            <x-social-auth />
        </div>
    </x-card>

    <p class="text-center text-sm text-muted">
        Need an account?
        <a class="text-accent underline hover:no-underline" href="{{ route('users.create') }}">Register</a>.
    </p>
</x-layout>
