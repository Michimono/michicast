@props([
    'index',
    'rule' => [],
    'types' => [],
])

@php
    $type = old("rules.{$index}.type", $rule['type'] ?? \App\Enums\BroadcastTransformRuleType::StripHtml->value);
    $value = old("rules.{$index}.value", $rule['value'] ?? '');
    $length = old("rules.{$index}.length", $rule['length'] ?? '');
    $pattern = old("rules.{$index}.pattern", $rule['pattern'] ?? '');
    $replacement = old("rules.{$index}.replacement", $rule['replacement'] ?? '');
@endphp

<div class="flex flex-col gap-2 rounded-md border border-line bg-paper-raised p-3" data-transform-rule>
    <div class="flex flex-wrap items-start justify-between gap-2">
        <div class="min-w-0 flex-1">
            <x-select
                :name="'rules['.$index.'][type]'"
                label="Rule"
                :options="$types"
                :value="$type"
                data-transform-type
            />
        </div>

        <button
            type="button"
            class="mt-7 text-sm text-muted hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-accent"
            data-transform-remove
        >
            Remove
        </button>
    </div>

    <div class="grid gap-2 sm:grid-cols-2">
        <div data-transform-length-wrap class="sm:col-span-2">
            <x-input
                :name="'rules['.$index.'][length]'"
                type="number"
                label="Length"
                :value="$length"
                min="1"
                max="10000"
                hint="Truncates on a word boundary."
            />
        </div>

        <div data-transform-value-wrap class="sm:col-span-2">
            <x-input
                :name="'rules['.$index.'][value]'"
                label="Text"
                :value="$value"
            />
        </div>

        <div data-transform-pattern-wrap>
            <x-input
                :name="'rules['.$index.'][pattern]'"
                label="Pattern"
                :value="$pattern"
            />
        </div>

        <div data-transform-replacement-wrap>
            <x-input
                :name="'rules['.$index.'][replacement]'"
                label="Replacement"
                :value="$replacement"
            />
        </div>
    </div>
</div>
