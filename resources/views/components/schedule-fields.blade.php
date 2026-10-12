@props([
    'frequencies',
    'weekdays',
    'timezones',
    'schedule',
    'selectedTimezone' => null,
    'timezoneName' => null,
    'timezoneSource' => null,
    // Single-column create page (#200): timezone becomes its own section.
    'separateTimezone' => false,
])

@php
    $frequency = (string) old('frequency', $schedule['frequency'] ?? 'daily');

    // Tile order and copy follow the mockup (#200); values are the existing
    // ScheduleFrequency cases, so nothing posts differently.
    $tiles = [
        'interval' => ['icon' => 'clock', 'help' => 'Run at a fixed interval.'],
        'daily' => ['icon' => 'calendar-day', 'help' => 'Run once a day at a set time.'],
        'weekly' => ['icon' => 'calendar-week', 'help' => 'Run on chosen days at a set time.'],
    ];
    $tiles = array_intersect_key($tiles, $frequencies);

    $panels = [
        'interval' => ['title' => 'Every few hours', 'text' => 'The broadcast runs at a fixed interval, around the clock.'],
        'daily' => ['title' => 'Daily', 'text' => 'The broadcast runs once every day at the time you choose.'],
        'weekly' => ['title' => 'Weekly', 'text' => 'Choose one or more days and the time to run on them.'],
    ];

    $checkedWeekdays = array_map('strval', (array) old('weekdays', $schedule['weekdays'] ?? []));
    $shortDays = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
@endphp

<div class="flex min-w-0 flex-col gap-5" data-schedule-fields>
    <fieldset class="flex flex-col gap-3">
        <legend class="mb-1 text-sm font-medium">
            Schedule<span class="ml-0.5 text-state-fail" aria-hidden="true">*</span>
        </legend>
        <p class="text-sm text-muted">Choose how often this broadcast should run.</p>

        <div class="grid grid-cols-3 gap-2 sm:gap-3">
            @foreach ($tiles as $value => $tile)
                <label class="relative flex cursor-pointer flex-col items-center justify-center gap-1.5 rounded-lg border border-line bg-paper-raised px-2 py-4 text-center sm:px-3 sm:py-5 transition-colors hover:border-line-strong has-checked:border-accent has-checked:bg-accent/5 has-checked:ring-1 has-checked:ring-accent has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-focus-visible:outline-accent">
                    <input
                        class="peer sr-only"
                        type="radio"
                        name="frequency"
                        value="{{ $value }}"
                        data-schedule-frequency
                        @checked($frequency === $value)
                    >
                    <x-dynamic-component :component="'icons.'.$tile['icon']" class="size-7 text-muted peer-checked:text-accent" />
                    <span class="text-sm font-medium text-ink">{{ $frequencies[$value] }}</span>
                    <span class="hidden text-xs text-muted sm:block">{{ $tile['help'] }}</span>
                </label>
            @endforeach
        </div>

        @error('frequency')
            <p class="text-sm text-state-fail">{{ $message }}</p>
        @enderror
    </fieldset>

    <div class="flex flex-col gap-4 rounded-lg border border-line bg-paper p-5">
        @foreach ($panels as $value => $panel)
            <div class="flex flex-col gap-1" data-schedule-panel="{{ $value }}">
                <h3 class="text-sm font-medium text-ink">{{ $panel['title'] }}</h3>
                <p class="text-sm text-muted">{{ $panel['text'] }}</p>
            </div>
        @endforeach

        <fieldset class="flex flex-col gap-2" data-schedule-panel="weekly">
            <legend class="sr-only">Weekdays</legend>

            <div class="flex flex-wrap gap-2">
                @foreach ($weekdays as $value => $label)
                    <label class="cursor-pointer">
                        <input
                            class="peer sr-only"
                            type="checkbox"
                            name="weekdays[]"
                            value="{{ $value }}"
                            @checked(in_array((string) $value, $checkedWeekdays, true))
                            @error('weekdays') aria-invalid="true" aria-describedby="weekdays-error" @enderror
                        >
                        <span
                            class="grid h-10 w-12 place-items-center rounded-md border border-line bg-paper-raised text-sm font-medium text-muted transition-colors hover:border-line-strong peer-checked:border-accent peer-checked:bg-accent/10 peer-checked:text-accent peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-accent"
                            title="{{ $label }}"
                        ><span aria-hidden="true">{{ $shortDays[$value] ?? $label }}</span><span class="sr-only">{{ $label }}</span></span>
                    </label>
                @endforeach
            </div>

            @error('weekdays')
                <p id="weekdays-error" class="text-sm text-state-fail">{{ $message }}</p>
            @enderror
        </fieldset>

        <div class="max-w-48" data-schedule-panel="daily weekly">
            <x-input
                name="time"
                type="time"
                label="Run at"
                :value="old('time', $schedule['time'] ?? '')"
            />
        </div>

        <div class="flex flex-col gap-1.5" data-schedule-panel="interval">
            <div class="flex items-end gap-3">
                <div class="max-w-32">
                    <x-input
                        name="hours"
                        type="number"
                        label="Run every"
                        :value="old('hours', $schedule['hours'] ?? '')"
                        min="1"
                        max="168"
                    />
                </div>
                <span class="pb-2 text-sm text-ink">hours</span>
            </div>
            <p class="text-sm text-muted">From 1 hour up to 168 hours (one week).</p>
        </div>
    </div>

    <div @class(['flex flex-col gap-2', 'mt-1 border-t border-line pt-6' => $separateTimezone])>
        <x-timezone-picker
            :value="old('timezone', $selectedTimezone)"
            :options="$timezones"
            :hint="$separateTimezone
                ? 'Schedules use this timezone, regardless of who views them. Leave it on the organization timezone unless this broadcast should run on a different clock.'
                : 'Leave this on the organization timezone unless this broadcast should run on a different clock.'"
        />

        @if ($timezoneName)
            <p class="flex items-start gap-2 rounded-md border border-accent/20 bg-accent/5 px-3 py-2.5 text-sm text-accent">
                <x-icons.info class="mt-0.5 size-4 shrink-0" />
                <span>Runs are calculated in {{ $timezoneName }}, the {{ $timezoneSource }}. They are stored in UTC.</span>
            </p>
        @endif
    </div>
</div>
