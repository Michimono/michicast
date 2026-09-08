@props(['name', 'label', 'value' => null, 'options' => [], 'hint' => null])

@php
    $choices = array_is_list($options) ? array_combine($options, $options) : $options;
    $selected = old($name, $value);
    $described = array_filter([$hint ? $name.'-hint' : null, $errors->has($name) ? $name.'-error' : null]);
@endphp

<div class="flex flex-col gap-1.5">
    <label class="text-sm font-medium" for="{{ $name }}">{{ $label }}</label>

    <select
        id="{{ $name }}"
        name="{{ $name }}"
        @error($name) aria-invalid="true" @enderror
        @if ($described) aria-describedby="{{ implode(' ', $described) }}" @endif
        {{ $attributes->class([
            'w-full rounded-md border bg-white px-3 py-2 text-sm focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-zinc-900',
            'border-zinc-300' => ! $errors->has($name),
            'border-red-500' => $errors->has($name),
        ]) }}
    >
        @foreach ($choices as $choice => $choiceLabel)
            <option value="{{ $choice }}" @selected((string) $choice === (string) $selected)>{{ $choiceLabel }}</option>
        @endforeach
    </select>

    @if ($hint)
        <p id="{{ $name }}-hint" class="text-sm text-zinc-600">{{ $hint }}</p>
    @endif

    @error($name)
        <p id="{{ $name }}-error" class="text-sm text-red-700">{{ $message }}</p>
    @enderror
</div>
