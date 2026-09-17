@props(['fields', 'preserving' => false])

{{-- Secrets are typed in, never shown. An empty edit keeps the stored value. --}}
@foreach ($fields as $field)
    <x-input
        :name="'credentials['.$field->name.']'"
        :label="$field->label"
        :hint="$preserving ? 'Leave blank to keep the stored value.' : $field->hint"
        type="password"
        autocomplete="new-password"
        value=""
    />
@endforeach
