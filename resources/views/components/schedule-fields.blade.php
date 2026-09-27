@props([
    'frequencies',
    'weekdays',
    'timezones',
    'schedule',
    'selectedTimezone' => null,
    'timezoneName' => null,
    'timezoneSource' => null,
])

@php
    $frequency = old('frequency', $schedule['frequency'] ?? 'daily');
@endphp

<div class="flex flex-col gap-4" data-schedule-fields>
    @if ($timezoneName)
        <p class="text-sm text-ink">
            Runs are calculated in {{ $timezoneName }}, the {{ $timezoneSource }}.
            They are stored in UTC.
        </p>
    @endif

    <x-select
        name="frequency"
        label="Frequency"
        :value="$frequency"
        :options="$frequencies"
        data-schedule-frequency
    />

    <div data-schedule-panel="daily weekly" @if (! in_array($frequency, ['daily', 'weekly'], true)) hidden @endif>
        <x-input
            name="time"
            type="time"
            label="Time"
            :value="old('time', $schedule['time'] ?? '')"
        />
    </div>

    <fieldset
        class="flex flex-col gap-1.5"
        data-schedule-panel="weekly"
        @if ($frequency !== 'weekly') hidden @endif
    >
        <legend class="text-sm font-medium">Weekdays</legend>
        <p class="text-sm text-muted">Which days this broadcast should run.</p>

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

    <div data-schedule-panel="interval" @if ($frequency !== 'interval') hidden @endif>
        <x-input
            name="hours"
            type="number"
            label="Hours between runs"
            :value="old('hours', $schedule['hours'] ?? '')"
            min="1"
            max="168"
        />
    </div>

    <x-select
        name="timezone"
        label="Timezone"
        :value="old('timezone', $selectedTimezone)"
        :options="$timezones"
        hint="Leave this on the organization timezone unless this broadcast should run on a different clock."
    />
</div>