<x-layout title="Edit {{ $project->name }}">
    <div class="mx-auto flex w-full max-w-lg flex-col gap-8">
        <x-card heading="Edit broadcast" kicker="Broadcasts" eared>
            <p class="text-sm text-ink">
                Rename the broadcast and adjust when it runs. The slug stays {{ $project->slug }}.
            </p>

            <form class="flex flex-col gap-4" method="POST" action="{{ route('broadcasts.update', $project) }}">
                @csrf
                @method('PATCH')

                <x-input name="name" label="Broadcast name" :value="$project->name" autofocus required />

                <x-textarea name="description" label="Description" :value="$project->description" />

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

                <div class="flex flex-wrap gap-2">
                    <x-button
                        variant="ghost"
                        :paw="false"
                        formaction="{{ route('broadcast-schedule-previews.store', $project) }}"
                    >Preview upcoming runs</x-button>
                    <x-button>Save</x-button>
                </div>
            </form>
        </x-card>

        <x-card heading="Upcoming runs" kicker="Preview">
            @if ($upcoming === [])
                <p class="text-sm text-muted">Save a complete schedule, or preview it, to see the next runs.</p>
            @else
                <p class="text-sm text-muted">Shown in {{ $timezoneName }}, the {{ $timezoneSource }}.</p>
                <ul class="flex flex-col divide-y divide-line border-t border-line">
                    @foreach ($upcoming as $run)
                        <li class="font-mono text-sm py-3">{{ $run }}</li>
                    @endforeach
                </ul>
            @endif
        </x-card>
    </div>
</x-layout>
