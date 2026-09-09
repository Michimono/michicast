@props(['name', 'label', 'type' => 'text', 'value' => null])

<div class="flex flex-col gap-1.5">
    <label class="text-sm font-medium" for="{{ $name }}">{{ $label }}</label>

    <input
        id="{{ $name }}"
        name="{{ $name }}"
        type="{{ $type }}"
        value="{{ old($name, $value) }}"
        @error($name) aria-invalid="true" aria-describedby="{{ $name }}-error" @enderror
        {{ $attributes->class([
            'w-full rounded-md border bg-paper-raised px-3 py-2 text-sm text-ink focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-accent',
            'border-line' => ! $errors->has($name),
            'border-state-fail' => $errors->has($name),
        ]) }}
    >

    @error($name)
        <p id="{{ $name }}-error" class="text-sm text-state-fail">{{ $message }}</p>
    @enderror
</div>
