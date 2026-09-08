<x-layout title="Forgot password">
    <x-card heading="Reset your password" class="max-w-md">
        <p class="text-sm text-ink">
            Tell us the address you registered with and we will email you a link to set a new password.
        </p>

        <form class="flex flex-col gap-4" method="POST" action="{{ route('password-reset-links.store') }}">
            @csrf

            <x-input name="email" label="Email address" type="email" autocomplete="email" autofocus required />

            <x-button>Email a reset link</x-button>
        </form>

        <p class="text-sm text-muted">
            <a class="text-accent underline hover:no-underline" href="{{ route('session.create') }}">Back to log in</a>
        </p>
    </x-card>
</x-layout>
