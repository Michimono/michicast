@props(['href' => null, 'variant' => 'primary', 'paw' => null])

@php
    $classes = match ($variant) {
        'ghost' => 'cursor-pointer group inline-flex items-center justify-center gap-2 rounded-md border border-line-strong bg-transparent px-4 py-2 text-sm font-medium text-ink hover:border-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent disabled:cursor-not-allowed disabled:opacity-50 disabled:hover:border-line-strong',
        default => 'cursor-pointer group inline-flex items-center justify-center gap-2 rounded-md bg-accent px-4 py-2 text-sm font-medium text-white hover:bg-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent disabled:cursor-not-allowed disabled:opacity-50 disabled:hover:bg-accent',
    };
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->class($classes) }}>
        @if ($paw ?? $variant === 'primary')
            <x-icons.paw class="size-4 transition-transform group-hover:-rotate-12" />
        @endif

        {{ $slot }}
    </a>
@else
    <button {{ $attributes->class($classes)->merge(['type' => 'submit']) }}>
        @if ($paw ?? $variant === 'primary')
            <x-icons.paw class="size-4 transition-transform group-hover:-rotate-12" />
        @endif

        {{ $slot }}
    </button>
@endif
