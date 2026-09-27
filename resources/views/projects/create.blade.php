<x-layout title="New broadcast">
    <x-card heading="New broadcast" kicker="Broadcasts" eared class="max-w-md">
        <p class="text-sm text-ink">
            Name it after the feed it will run, for example a show or a news beat. Set how often it should run below.
        </p>

        <form class="flex flex-col gap-4" method="POST" action="{{ route('broadcasts.store') }}">
            @csrf

            <x-input name="name" label="Broadcast name" autofocus required />

            <x-textarea name="description" label="Description" hint="Optional. Shown to everyone in the organization." />

            <div class="border-t border-line pt-4">
                <h2 class="mb-3 font-display text-lg tracking-tight">Schedule</h2>
                <x-schedule-fields
                    :frequencies="$frequencies"
                    :weekdays="$weekdays"
                    :timezones="$timezones"
                    :schedule="$schedule"
                    :selected-timezone="$selectedTimezone"
                    :timezone-name="$timezoneName"
                    :timezone-source="$timezoneSource"
                />
            </div>

            <x-button>Create broadcast</x-button>
        </form>
    </x-card>
</x-layout>