<x-layout title="Register">
    <x-card heading="Create your account" class="max-w-md">
        <form class="flex flex-col gap-4" method="POST" action="{{ route('users.store') }}">
            @csrf

            <x-input name="name" label="Name" autocomplete="name" autofocus required />
            <x-input name="email" label="Email address" type="email" autocomplete="email" required />
            <x-input name="password" label="Password" type="password" autocomplete="new-password" required />
            <x-input name="password_confirmation" label="Confirm password" type="password" autocomplete="new-password" required />

            <x-button>Create account</x-button>
        </form>

        <p class="text-sm text-zinc-600">
            Already have an account?
            <a class="underline hover:no-underline" href="{{ route('session.create') }}">Log in</a>.
        </p>
    </x-card>
</x-layout>
