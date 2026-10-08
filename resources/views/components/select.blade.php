@props(['name', 'label', 'value' => null, 'options' => [], 'hint' => null, 'size' => 'md'])

@php
    // A field posted as "config[format]" is read back as "config.format".
    $field = rtrim(str_replace(['[', ']'], ['.', ''], $name), '.');
    $choices = array_is_list($options) ? array_combine($options, $options) : $options;
    $selected = old($field, $value);
    $described = array_filter([$hint ? $field.'-hint' : null, $errors->has($field) ? $field.'-error' : null]);
    // size="lg": the taller, roomier control of focused pages (first organization).
    $large = $size === 'lg';
@endphp

<div @class(['flex flex-col', 'gap-1.5' => ! $large, 'gap-2' => $large])>
    <label class="text-sm font-medium" for="{{ $field }}">{{ $label }}</label>

    @if ($large)
        <div class="relative">
    @endif

    <select
        id="{{ $field }}"
        name="{{ $name }}"
        @error($field) aria-invalid="true" @enderror
        @if ($described) aria-describedby="{{ implode(' ', $described) }}" @endif
        {{ $attributes->class([
            'w-full rounded-md border bg-paper-raised px-3 py-2 text-sm text-ink focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-accent' => ! $large,
            'h-12 w-full cursor-pointer appearance-none rounded-lg border bg-paper-raised pr-11 pl-4 text-base text-ink transition-colors focus-visible:border-accent focus-visible:outline-3 focus-visible:outline-offset-2 focus-visible:outline-accent/30' => $large,
            'border-line' => ! $errors->has($field),
            'hover:border-line-strong' => $large && ! $errors->has($field),
            'border-state-fail' => $errors->has($field),
        ]) }}
    >
        @foreach ($choices as $choice => $choiceLabel)
            <option value="{{ $choice }}" @selected((string) $choice === (string) $selected)>{{ $choiceLabel }}</option>
        @endforeach
    </select>

    @if ($large)
            <svg class="pointer-events-none absolute top-1/2 right-4 size-4 -translate-y-1/2 text-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="m6 9 6 6 6-6" />
            </svg>
        </div>
    @endif

    @if ($hint)
        <p id="{{ $field }}-hint" class="text-sm text-muted">{{ $hint }}</p>
    @endif

    @error($field)
        <p id="{{ $field }}-error" class="text-sm text-state-fail">{{ $message }}</p>
    @enderror
</div>
