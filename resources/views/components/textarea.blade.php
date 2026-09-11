@props(['name', 'label', 'value' => null, 'rows' => 4, 'hint' => null])

@php
    $described = array_filter([$hint ? $name.'-hint' : null, $errors->has($name) ? $name.'-error' : null]);
@endphp

<div class="flex flex-col gap-1.5">
    <label class="text-sm font-medium" for="{{ $name }}">{{ $label }}</label>

    <textarea
        id="{{ $name }}"
        name="{{ $name }}"
        rows="{{ $rows }}"
        @error($name) aria-invalid="true" @enderror
        @if ($described) aria-describedby="{{ implode(' ', $described) }}" @endif
        {{ $attributes->class([
            'w-full rounded-md border bg-paper-raised px-3 py-2 text-sm text-ink focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-accent',
            'border-line' => ! $errors->has($name),
            'border-state-fail' => $errors->has($name),
        ]) }}
    >{{ old($name, $value) }}</textarea>

    @if ($hint)
        <p id="{{ $name }}-hint" class="text-sm text-muted">{{ $hint }}</p>
    @endif

    @error($name)
        <p id="{{ $name }}-error" class="text-sm text-state-fail">{{ $message }}</p>
    @enderror
</div>
