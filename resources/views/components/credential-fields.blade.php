@props(['fields', 'masks' => [], 'preserving' => false])

{{-- Secrets are typed in, never shown. A stored one appears only as a short masked suffix. An empty edit keeps the stored value. --}}
@foreach ($fields as $field)
    @php
        $mask = $masks[$field->name] ?? null;
    @endphp

    <x-input
        :name="'credentials['.$field->name.']'"
        :label="$field->label"
        :hint="$preserving ? ($mask ? 'Stored value '.$mask.'. ' : '').'Leave blank to keep the stored value.' : $field->hint"
        type="password"
        autocomplete="new-password"
        value=""
    />
@endforeach
