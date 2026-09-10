<x-layout title="Organization settings">
    <x-card heading="Organization settings" kicker="Settings" eared class="max-w-md">
        <p class="text-sm text-ink">
            Name and timezone belong to the organization. They are the same for every member.
        </p>

        <form class="flex flex-col gap-4" method="POST" action="{{ route('organization-settings.update') }}">
            @csrf
            @method('PATCH')

            <x-input name="name" label="Organization name" :value="$organization->name" autocomplete="organization" required />

            <x-select
                name="timezone"
                label="Organization timezone"
                :value="$organization->timezone"
                :options="$timezones"
                hint="This timezone drives scheduling. Your profile timezone only changes how times appear on screen."
            />

            <x-button>Save settings</x-button>
        </form>
    </x-card>
</x-layout>
