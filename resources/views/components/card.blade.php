@props(['heading' => null, 'kicker' => null, 'eared' => false])

<section {{ $attributes->class('relative flex flex-col gap-3 rounded-card border border-line bg-paper-raised p-6') }}>
    @if ($eared)
        <svg
            class="absolute -top-2.5 left-6 h-2.5 w-16 text-line"
            viewBox="0 0 64 10"
            fill="var(--color-paper-raised)"
            stroke="currentColor"
            aria-hidden="true"
        >
            <path d="M2 10 10 1 18 10" />
            <path d="M30 10 38 1 46 10" />
        </svg>
    @endif

    @if ($kicker)
        <p class="flex items-center gap-2.5 font-mono text-[0.6875rem] tracking-[0.12em] text-muted uppercase">
            {{ $kicker }}

            <span class="house-fill h-0.5 flex-1 rounded-full"></span>
        </p>
    @endif

    @if ($heading)
        <h2 class="font-display text-xl tracking-tight">{{ $heading }}</h2>
    @endif

    {{ $slot }}
</section>
