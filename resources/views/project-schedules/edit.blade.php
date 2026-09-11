<x-layout title="Schedule {{ $project->name }}">
    <x-card heading="Schedule" kicker="Projects" eared class="max-w-md">
        <p class="text-sm text-ink">
            Runs are calculated in {{ $timezoneName }}, the {{ $timezoneSource }}.
            They are stored in UTC.
        </p>

        <form class="flex flex-col gap-4" method="POST" action="{{ route('project-schedules.update', $project) }}">
            @csrf
            @method('PATCH')

            <x-select name="frequency" label="Frequency" :value="old('frequency', $schedule['frequency'] ?? 'daily')" :options="$frequencies" />

            <x-input
                name="time"
                type="time"
                label="Time"
                :value="old('time', $schedule['time'] ?? '')"
            />

            <fieldset class="flex flex-col gap-1.5">
                <legend class="text-sm font-medium">Weekdays</legend>
                <p class="text-sm text-muted">Used when the frequency is weekly.</p>

                <div class="flex flex-col gap-1">
                    @foreach ($weekdays as $value => $label)
                        <label class="flex items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                name="weekdays[]"
                                value="{{ $value }}"
                                @checked(in_array((string) $value, array_map('strval', old('weekdays', $schedule['weekdays'] ?? [])), true))
                            >
                            {{ $label }}
                        </label>
                    @endforeach
                </div>

                @error('weekdays')
                    <p class="text-sm text-state-fail">{{ $message }}</p>
                @enderror
            </fieldset>

            <x-input
                name="hours"
                type="number"
                label="Hours between runs"
                :value="old('hours', $schedule['hours'] ?? '')"
                min="1"
                max="168"
            />

            <x-select
                name="timezone"
                label="Timezone"
                :value="old('timezone', $selectedTimezone)"
                :options="$timezones"
                hint="Leave this on the organization timezone unless this project should run on a different clock."
            />

            <div class="flex flex-wrap gap-2">
                <x-button
                    variant="ghost"
                    :paw="false"
                    formaction="{{ route('project-schedule-previews.store', $project) }}"
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
