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
            @if ($mode === \App\Enums\RegistrationMode::Closed)
                <div class="flex flex-col gap-2" role="status">
                    @if ($capReached)
                        <p class="text-sm font-medium text-ink">New sign-ups are paused for today.</p>
                        <p class="text-sm text-muted">We have reached today's limit on new accounts. Please try again tomorrow.</p>
                    @else
                        <p class="text-sm font-medium text-ink">Registration is currently closed.</p>
                        <p class="text-sm text-muted">New accounts are not being accepted right now. If you already have an account, you can still log in.</p>
                    @endif
                </div>
            @else
                @if ($mode === \App\Enums\RegistrationMode::InviteOnly)
                    <div class="flex flex-col gap-2" role="status">
                        <p class="text-sm font-medium text-ink">Registration is by invitation only.</p>
                        <p class="text-sm text-muted">Use the email address your invitation was sent to.</p>
                    </div>
                @endif

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
            @endif
        </div>
    </x-card>

    <p class="text-center text-sm text-muted">
        Already have an account?
        <a class="text-accent underline hover:no-underline" href="{{ route('session.create') }}">Log in</a>.
    </p>
</x-layout>
