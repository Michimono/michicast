@props(['fields', 'values' => []])

{{-- Renders whatever the chosen provider declares, so no screen knows a provider by name. --}}
@foreach ($fields as $field)
    @php
        $name = 'config['.$field->name.']';
        $value = data_get($values, $field->name);
    @endphp

    @switch($field->type)
        @case(App\Enums\ConfigurationFieldType::Textarea)
            <x-textarea :name="$name" :label="$field->label" :hint="$field->hint" :value="$value" />

            @break

        @case(App\Enums\ConfigurationFieldType::Select)
            <x-select :name="$name" :label="$field->label" :hint="$field->hint" :value="$value" :options="$field->options" />

            @break

        @default
            <x-input :name="$name" :label="$field->label" :hint="$field->hint" :value="$value" :type="$field->type->value" />
    @endswitch
@endforeach
