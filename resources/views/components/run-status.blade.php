@props(['status'])

@php
    $tone = $status->tone();
    $classes = match ($tone) {
        'ok' => 'border-state-ok/40 text-state-ok',
        'fail' => 'border-state-fail/40 text-state-fail',
        'warn' => 'border-state-warn/40 text-state-warn',
        'skip' => 'border-state-skip/40 text-state-skip',
        default => 'border-line text-muted',
    };
@endphp

<span {{ $attributes->class('inline-flex w-fit self-start rounded-full border px-2 py-0.5 font-mono text-xs uppercase tracking-wide '.$classes) }}>
    {{ $status->label() }}
</span>
