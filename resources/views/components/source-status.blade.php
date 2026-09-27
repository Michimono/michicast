@props(['source'])

@php
    $label = match (true) {
        $source->isArchived() => 'Archived',
        $source->enabled => 'Enabled',
        default => 'Disabled',
    };
@endphp

<span {{ $attributes->class('inline-flex w-fit self-start rounded-full border border-line px-2 py-0.5 font-mono text-xs uppercase tracking-wide text-muted') }}>
    {{ $label }}
</span>
