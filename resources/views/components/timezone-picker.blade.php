@props([
    'name' => 'timezone',
    'label' => 'Timezone',
    // A list of identifiers, or value => label (e.g. '' => 'Use organization timezone (…)').
    'options' => [],
    'value' => null,
    'hint' => null,
    'autofocus' => false,
])

@php
    // Searchable timezone picker (#194, shared since #200).
    // A real <select> stretched invisibly over a styled face. app.js swaps the face's
    // name for a searchable combobox that drives the select, so the posted field stays
    // native and server-side validation is unchanged; without JS the select works as-is.
    $choices = array_is_list($options) ? array_combine($options, $options) : $options;
    $selected = (string) old($name, $value);
    $selectedLabel = (string) ($choices[$selected] ?? $selected);

    // Friendly subtitle per option: offset and abbreviation, e.g. "UTC+02:00 · CEST".
    $subtitle = static function (string $zone): string {
        if ($zone === '') {
            return 'Follows the organization setting';
        }

        try {
            $now = new DateTimeImmutable('now', new DateTimeZone($zone));
        } catch (Exception) {
            return '';
        }

        $offset = 'UTC'.$now->format('P');
        $abbreviation = $now->format('T');

        return ctype_alpha($abbreviation) && $abbreviation !== 'UTC' ? $offset.' · '.$abbreviation : $offset;
    };
    $described = array_filter([$hint ? $name.'-hint' : null, $errors->has($name) ? $name.'-error' : null]);
@endphp

<div {{ $attributes->class(['flex flex-col gap-2']) }}>
    <label class="text-sm font-medium" for="{{ $name }}">{{ $label }}</label>

    <div
        @class([
            'relative flex h-14 items-center gap-3 rounded-lg border bg-ink/4 pr-11 pl-4 transition-colors has-[select:focus-visible]:border-accent has-[select:focus-visible]:bg-paper-raised has-[select:focus-visible]:outline-3 has-[select:focus-visible]:outline-offset-2 has-[select:focus-visible]:outline-accent/30 has-[input:focus]:border-accent has-[input:focus]:bg-paper-raised has-[input:focus]:outline-3 has-[input:focus]:outline-offset-2 has-[input:focus]:outline-accent/30',
            'border-line hover:border-line-strong' => ! $errors->has($name),
            'border-state-fail' => $errors->has($name),
        ])
        data-timezone-picker
    >
        <svg class="size-5 shrink-0 text-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <circle cx="12" cy="12" r="8.5" />
            <path d="M3.5 12h17" />
            <path d="M12 3.5c2.3 2.4 3.5 5.2 3.5 8.5s-1.2 6.1-3.5 8.5c-2.3-2.4-3.5-5.2-3.5-8.5s1.2-6.1 3.5-8.5z" />
        </svg>

        <span class="flex min-w-0 flex-col leading-tight" aria-hidden="true">
            <span class="truncate text-base text-ink" data-timezone-name>{{ $selectedLabel }}</span>
            <span class="truncate text-xs text-muted" data-timezone-subtitle>{{ $subtitle($selected) }}</span>
        </span>

        <svg class="pointer-events-none absolute top-1/2 right-4 size-4 -translate-y-1/2 text-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="m8 9 4-4 4 4" />
            <path d="m8 15 4 4 4-4" />
        </svg>

        <select
            id="{{ $name }}"
            name="{{ $name }}"
            @error($name) aria-invalid="true" @enderror
            @if ($described) aria-describedby="{{ implode(' ', $described) }}" @endif
            @if ($autofocus) autofocus="autofocus" @endif
            class="absolute inset-0 size-full cursor-pointer appearance-none rounded-lg opacity-0"
        >
            @foreach ($choices as $zone => $zoneLabel)
                <option value="{{ $zone }}" @selected((string) $zone === $selected) data-subtitle="{{ $subtitle((string) $zone) }}">{{ $zoneLabel }}</option>
            @endforeach
        </select>
    </div>

    @if ($hint)
        <p id="{{ $name }}-hint" class="text-sm text-muted">{{ $hint }}</p>
    @endif

    @error($name)
        <p id="{{ $name }}-error" class="text-sm text-state-fail">{{ $message }}</p>
    @enderror
</div>
