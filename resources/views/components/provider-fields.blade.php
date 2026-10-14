@props(['fields', 'values' => [], 'size' => 'md', 'idPrefix' => null, 'grid' => false])

{{--
    Renders whatever the chosen provider declares, so no screen knows a provider by name.
    idPrefix keeps ids unique when several types' groups share one page; grid lays
    fields out in two columns on sm+ with adjacent selects side by side.
--}}
@php
    $list = array_values($fields);
    $isSelect = fn ($index) => isset($list[$index]) && $list[$index]->type === App\Enums\ConfigurationFieldType::Select;
@endphp

@foreach ($list as $index => $field)
    @php
        $name = 'config['.$field->name.']';
        $value = data_get($values, $field->name);
        $id = $idPrefix ? $idPrefix.'-'.$field->name : null;
        $label = $field->optional ? $field->label.' (optional)' : $field->label;
        $half = $grid && $isSelect($index) && ($isSelect($index - 1) || $isSelect($index + 1));
    @endphp

    @if ($grid)
        <div @class(['min-w-0', 'sm:col-span-2' => ! $half])>
    @endif

    @switch($field->type)
        @case(App\Enums\ConfigurationFieldType::Textarea)
            <x-textarea :name="$name" :label="$label" :hint="$field->hint" :value="$value" :placeholder="$field->placeholder" :required="$field->required" />

            @break

        @case(App\Enums\ConfigurationFieldType::Select)
            <x-select :name="$name" :id="$id" :label="$label" :hint="$field->hint" :value="$value" :options="$field->options" :size="$size" />

            @break

        @default
            <x-input :name="$name" :id="$id" :label="$label" :hint="$field->hint" :value="$value" :type="$field->type->value" :size="$size" :placeholder="$field->placeholder" :required="$field->required" />
    @endswitch

    @if ($grid)
        </div>
    @endif
@endforeach
