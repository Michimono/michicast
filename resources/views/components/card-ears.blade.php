@props(['rainbow' => false])

@php
    $gradientId = $rainbow ? 'card-ears-'.str_replace('.', '', uniqid('', true)) : null;
@endphp

<svg
    {{ $attributes->class([
        'absolute -top-2.5 left-6 h-2.5 w-16',
        'text-line' => ! $rainbow,
    ]) }}
    viewBox="0 0 64 10"
    fill="var(--color-paper-raised)"
    stroke="{{ $rainbow ? 'url(#'.$gradientId.')' : 'currentColor' }}"
    aria-hidden="true"
>
    @if ($rainbow)
        <defs>
            <linearGradient id="{{ $gradientId }}" x1="0%" y1="0%" x2="100%" y2="0%">
                <stop offset="0%" stop-color="var(--color-house-1)" />
                <stop offset="100%" stop-color="var(--color-house-2)" />
            </linearGradient>
        </defs>
    @endif
    <path d="M2 10 10 1 18 10" />
    <path d="M30 10 38 1 46 10" />
</svg>
