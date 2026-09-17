@props([
    'index',
    'rule' => [],
    'types' => [],
    'fields' => [],
])

@php
    $type = old("rules.{$index}.type", $rule['type'] ?? \App\Enums\BroadcastFilterRuleType::Contains->value);
    $field = old("rules.{$index}.field", $rule['field'] ?? 'title');
    $value = old("rules.{$index}.value", $rule['value'] ?? '');
    $hours = old("rules.{$index}.hours", $rule['hours'] ?? '');
@endphp

<div class="flex flex-col gap-2 rounded-md border border-line bg-paper-raised p-3" data-filter-rule>
    <div class="flex flex-wrap items-start justify-between gap-2">
        <div class="min-w-0 flex-1">
            <x-select
                :name="'rules['.$index.'][type]'"
                label="Rule"
                :options="$types"
                :value="$type"
                data-filter-type
            />
        </div>

        <button
            type="button"
            class="mt-7 text-sm text-muted hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-accent"
            data-filter-remove
        >
            Remove
        </button>
    </div>

    <div class="grid gap-2 sm:grid-cols-2" data-filter-fields>
        <div data-filter-field-wrap>
            <x-select
                :name="'rules['.$index.'][field]'"
                label="In"
                :options="$fields"
                :value="$field"
            />
        </div>

        <div data-filter-value-wrap>
            <x-input
                :name="'rules['.$index.'][value]'"
                label="Value"
                :value="$value"
            />
        </div>

        <div data-filter-hours-wrap class="sm:col-span-2">
            <x-input
                :name="'rules['.$index.'][hours]'"
                type="number"
                label="Hours"
                :value="$hours"
                min="1"
                max="8760"
                hint="Relative to the moment the project runs."
            />
        </div>
    </div>
</div>
