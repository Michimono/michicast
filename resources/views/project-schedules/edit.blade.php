<x-layout title="Schedule {{ $project->name }}">
    <x-card heading="Schedule" kicker="Broadcasts" eared class="max-w-md">
        <form class="flex flex-col gap-4" method="POST" action="{{ route('broadcast-schedules.update', $project) }}">
            @csrf
            @method('PATCH')

            <x-schedule-fields
                :frequencies="$frequencies"
                :weekdays="$weekdays"
                :timezones="$timezones"
                :schedule="$schedule"
                :selected-timezone="$selectedTimezone"
                :timezone-name="$timezoneName"
                :timezone-source="$timezoneSource"
            />

            <div class="flex flex-wrap gap-2">
                <x-button
                    variant="ghost"
                    :paw="false"
                    formaction="{{ route('broadcast-schedule-previews.store', $project) }}"
                >Preview upcoming runs</x-button>
                <x-button>Save schedule</x-button>
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
</x-layout>