@props(['name', 'label', 'type' => 'text', 'value' => null, 'hint' => null])

@php
    // A field posted as "config[feed_url]" is read back as "config.feed_url".
    $field = rtrim(str_replace(['[', ']'], ['.', ''], $name), '.');
    $described = array_filter([$hint ? $field.'-hint' : null, $errors->has($field) ? $field.'-error' : null]);
@endphp

<div class="flex flex-col gap-1.5">
    <label class="text-sm font-medium" for="{{ $field }}">{{ $label }}</label>

    <input
        id="{{ $field }}"
        name="{{ $name }}"
        type="{{ $type }}"
        value="{{ old($field, $value) }}"
        @error($field) aria-invalid="true" @enderror
        @if ($described) aria-describedby="{{ implode(' ', $described) }}" @endif
        {{ $attributes->class([
            'w-full rounded-md border bg-paper-raised px-3 py-2 text-sm text-ink focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-accent',
            'border-line' => ! $errors->has($field),
            'border-state-fail' => $errors->has($field),
        ]) }}
    >

    @if ($hint)
        <p id="{{ $field }}-hint" class="text-sm text-muted">{{ $hint }}</p>
    @endif

    @error($field)
        <p id="{{ $field }}-error" class="text-sm text-state-fail">{{ $message }}</p>
    @enderror
</div>
