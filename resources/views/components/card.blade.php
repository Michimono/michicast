@props(['heading' => null, 'kicker' => null, 'eared' => false])

<section {{ $attributes->class('relative flex flex-col gap-3 rounded-card border border-line bg-paper-raised p-6') }}>
    @if ($eared)
        <x-card-ears />
    @endif

    @if ($kicker)
        <p class="flex items-center gap-2.5 font-mono text-[0.6875rem] tracking-[0.12em] text-muted uppercase">
            {{ $kicker }}

            <span class="house-fill h-0.5 flex-1 rounded-full"></span>
        </p>
    @endif

    @if ($heading || isset($actions))
        <div class="flex flex-wrap items-start justify-between gap-3">
            @if ($heading)
                <h2 class="font-display text-xl tracking-tight">{{ $heading }}</h2>
            @else
                <span></span>
            @endif

            @isset($actions)
                <div class="flex flex-wrap items-center gap-2">
                    {{ $actions }}
                </div>
            @endisset
        </div>
    @endif

    {{ $slot }}
</section>