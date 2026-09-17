@props(['broadcast'])

@php
    $label = match (true) {
        $broadcast->isArchived() => 'Archived',
        $broadcast->enabled => 'Enabled',
        default => 'Disabled',
    };
@endphp

<span {{ $attributes->class('inline-flex rounded-full border border-line px-2 py-0.5 font-mono text-xs uppercase tracking-wide text-muted') }}>
    {{ $label }}
</span>
