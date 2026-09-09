<x-layout title="Log in">
    <x-card heading="Log in" class="max-w-md">
        <form class="flex flex-col gap-4" method="POST" action="{{ route('session.store') }}">
            @csrf

            <x-input name="email" label="Email address" type="email" autocomplete="email" autofocus required />
            <x-input name="password" label="Password" type="password" autocomplete="current-password" required />

            <x-button>Log in</x-button>
        </form>

        <p class="text-sm text-muted">
            <a class="text-accent underline hover:no-underline" href="{{ route('password-reset-links.create') }}">Forgot your password?</a>
        </p>

        <p class="text-sm text-muted">
            Need an account?
            <a class="text-accent underline hover:no-underline" href="{{ route('users.create') }}">Register</a>.
        </p>
    </x-card>
</x-layout>
