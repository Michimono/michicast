<x-layout title="Settings">
    <div class="mx-auto flex w-full max-w-lg flex-col gap-8">
        <x-card id="profile" heading="Profile" eared class="scroll-mt-8">
            <form class="flex flex-col gap-4" method="POST" action="{{ route('profile.update') }}">
                @csrf
                @method('PATCH')

                <x-input name="name" label="Name" :value="$user->name" autocomplete="name" required />
                <x-input name="email" label="Email address" type="email" :value="$user->email" autocomplete="email" required />

                <x-select
                    name="timezone"
                    label="Display timezone"
                    :value="$user->timezone"
                    :options="$timezones"
                    hint="Only changes how times are shown to you. Schedules keep running on their own timezone."
                />

                <x-button>Save profile</x-button>
            </form>

            <p class="text-sm text-muted">
                Account created <x-timestamp :value="$user->created_at" />.
            </p>
        </x-card>

        <x-card id="password" heading="Password" class="scroll-mt-8">
            <p class="text-sm text-ink">Changing your password asks for the current one first.</p>

            <form class="flex flex-col gap-4" method="POST" action="{{ route('profile.password.update') }}">
                @csrf
                @method('PATCH')

                <x-input name="current_password" label="Current password" type="password" autocomplete="current-password" required />
                <x-input name="password" label="New password" type="password" autocomplete="new-password" required />
                <x-input name="password_confirmation" label="Confirm new password" type="password" autocomplete="new-password" required />

                <x-button>Update password</x-button>
            </form>
        </x-card>

        <x-card id="two-factor" heading="Two-factor authentication" class="scroll-mt-8">
            <p class="text-sm text-ink">
                Optional for every account, including platform admins. When enabled, a stolen password alone is not enough to sign in.
            </p>

            @if (is_array($recoveryCodes) && $recoveryCodes !== [])
                <div class="flex flex-col gap-2 rounded-md border border-line bg-paper p-4">
                    <p class="text-sm font-medium">Recovery codes</p>
                    <p class="text-sm text-muted">Store these somewhere safe. Each code works once, and they will not be shown again.</p>
                    <ul class="grid grid-cols-1 gap-1 font-mono text-sm sm:grid-cols-2">
                        @foreach ($recoveryCodes as $recoveryCode)
                            <li>{{ $recoveryCode }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if ($user->hasTwoFactorEnabled())
                <p class="text-sm text-state-ok">Two-factor authentication is enabled.</p>

                <form class="flex flex-col gap-4" method="POST" action="{{ route('profile.two-factor-recovery-codes.store') }}">
                    @csrf

                    <x-input name="password" label="Current password" type="password" autocomplete="current-password" required />
                    <x-button variant="ghost">Regenerate recovery codes</x-button>
                </form>

                <form class="flex flex-col gap-4" method="POST" action="{{ route('profile.two-factor-authentication.destroy') }}">
                    @csrf
                    @method('DELETE')

                    <x-input name="password" label="Current password" type="password" autocomplete="current-password" required />
                    <x-button variant="ghost">Disable two-factor authentication</x-button>
                </form>
            @elseif ($user->hasTwoFactorPending())
                <p class="text-sm text-ink">Scan this QR code with your authenticator app, or enter the secret manually.</p>

                @if ($twoFactorQrSvg)
                    <div class="mx-auto w-48 text-ink">
                        {!! $twoFactorQrSvg !!}
                    </div>
                @endif

                @if ($twoFactorSecret)
                    <p class="break-all font-mono text-sm">{{ $twoFactorSecret }}</p>
                @endif

                <form class="flex flex-col gap-4" method="POST" action="{{ route('profile.confirmed-two-factor-authentication.store') }}">
                    @csrf

                    <x-input name="code" label="Authentication code" autocomplete="one-time-code" required />
                    <x-button>Confirm and enable</x-button>
                </form>

                <form method="POST" action="{{ route('profile.two-factor-authentication.store') }}">
                    @csrf
                    <x-button variant="ghost">Generate a new secret</x-button>
                </form>

                <form method="POST" action="{{ route('profile.two-factor-authentication.destroy') }}">
                    @csrf
                    @method('DELETE')
                    <x-button variant="ghost">Cancel enrollment</x-button>
                </form>
            @else
                <form method="POST" action="{{ route('profile.two-factor-authentication.store') }}">
                    @csrf
                    <x-button>Enable two-factor authentication</x-button>
                </form>
            @endif
        </x-card>
    </div>
</x-layout>
