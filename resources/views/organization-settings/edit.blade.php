<x-layout title="Organization settings">
    <div class="mx-auto flex w-full max-w-md flex-col gap-8">
        <x-organization-usage-card :organization="$organization" />

        @can('update', $organization)
            <x-card id="settings" heading="Organization settings" kicker="Settings" eared class="scroll-mt-8">
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
        @endcan
    </div>
</x-layout>