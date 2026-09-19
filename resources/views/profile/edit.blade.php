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
    </div>
</x-layout>
