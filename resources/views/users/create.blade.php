<x-layout title="Register" centered :footer="false">
    <div class="flex flex-col items-center gap-5">
        <x-brand.mark variant="cat" class="h-16" />

        <div class="flex flex-col items-center gap-3">
            <h1 class="font-display text-3xl tracking-tight">Create your {{ config('app.name') }} account</h1>

            <span class="house-fill h-0.5 w-16 rounded-full"></span>
        </div>
    </div>

    <x-card eared>
        <div class="flex flex-col gap-6">
            <form class="flex flex-col gap-5" method="POST" action="{{ route('users.store') }}">
                @csrf

                <x-input name="name" label="Name" autocomplete="name" autofocus required />
                <x-input name="email" label="Email address" type="email" :value="$email" autocomplete="email" required />
                <x-input
                    name="password"
                    label="Password"
                    type="password"
                    autocomplete="new-password"
                    hint="At least 8 characters."
                    required
                />
                <x-input name="password_confirmation" label="Confirm password" type="password" autocomplete="new-password" required />

                <x-button class="w-full">Create account</x-button>
            </form>

            <x-social-auth />
        </div>
    </x-card>

    <p class="text-center text-sm text-muted">
        Already have an account?
        <a class="text-accent underline hover:no-underline" href="{{ route('session.create') }}">Log in</a>.
    </p>
</x-layout>
