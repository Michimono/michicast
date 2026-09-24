@props(['health'])

@php
    $classes = match ($health) {
        App\Enums\ProjectHealth::Healthy => 'border-state-ok/40 text-state-ok',
        App\Enums\ProjectHealth::AttentionNeeded => 'border-state-fail/40 text-state-fail',
        default => 'border-line text-muted',
    };
@endphp

<span {{ $attributes->class(['inline-flex rounded-full border px-2 py-0.5 font-mono text-xs tracking-wide uppercase', $classes]) }}>
    {{ $health->label() }}
</span>