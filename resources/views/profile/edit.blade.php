<x-layout title="Profile">
    <x-card heading="Your profile" class="max-w-md">
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

        <p class="text-sm text-zinc-600">
            Account created <x-timestamp :value="$user->created_at" />.
        </p>
    </x-card>

    <x-card heading="Password" class="max-w-md">
        <p class="text-sm text-zinc-700">Changing your password asks for the current one first.</p>

        <x-button href="{{ route('profile.password.edit') }}" class="self-start">Change password</x-button>
    </x-card>
</x-layout>
