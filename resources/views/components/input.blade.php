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
            'w-full rounded-md border bg-white px-3 py-2 text-sm focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-zinc-900',
            'border-zinc-300' => ! $errors->has($name),
            'border-red-500' => $errors->has($name),
        ]) }}
    >

    @error($name)
        <p id="{{ $name }}-error" class="text-sm text-red-700">{{ $message }}</p>
    @enderror
</div>
