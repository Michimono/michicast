@props(['name', 'label', 'type' => 'text', 'value' => null, 'hint' => null, 'size' => 'md'])

@php
    // A field posted as "config[feed_url]" is read back as "config.feed_url".
    $field = rtrim(str_replace(['[', ']'], ['.', ''], $name), '.');
    $described = array_filter([$hint ? $field.'-hint' : null, $errors->has($field) ? $field.'-error' : null]);
    // size="lg": the taller, roomier control of focused pages (first organization).
    $large = $size === 'lg';
@endphp

<div @class(['flex flex-col', 'gap-1.5' => ! $large, 'gap-2' => $large])>
    <label class="text-sm font-medium" for="{{ $field }}">{{ $label }}@if ($large && $attributes->has('required'))<span class="ml-0.5 text-state-fail" aria-hidden="true">*</span>@endif</label>

    <input
        id="{{ $field }}"
        name="{{ $name }}"
        type="{{ $type }}"
        value="{{ old($field, $value) }}"
        @error($field) aria-invalid="true" @enderror
        @if ($described) aria-describedby="{{ implode(' ', $described) }}" @endif
        {{ $attributes->class([
            'w-full rounded-md border bg-paper-raised px-3 py-2 text-sm text-ink focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-accent' => ! $large,
            'h-12 w-full rounded-lg border bg-paper-raised px-4 text-base text-ink transition-colors placeholder:text-muted/70 focus-visible:border-accent focus-visible:outline-3 focus-visible:outline-offset-2 focus-visible:outline-accent/30' => $large,
            'border-line' => ! $errors->has($field),
            'hover:border-line-strong' => $large && ! $errors->has($field),
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
