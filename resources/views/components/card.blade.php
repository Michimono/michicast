@props(['heading' => null, 'kicker' => null, 'eared' => false, 'highlighted' => false])

<section {{ $attributes->class([
    'relative flex flex-col gap-3 rounded-card bg-paper-raised p-6',
    'border border-line' => ! $highlighted,
    'border house-edge' => $highlighted,
]) }}>
    @if ($eared || $highlighted)
        <x-card-ears :rainbow="$highlighted" />
    @endif

    @if ($kicker)
        {{-- div, not p: kickerTrailing may include forms (Play/Pause). Browsers close <p> before <form>, which dumps trailing controls out of the flex row and left-aligns them. --}}
        <div class="flex items-center gap-2.5 font-mono text-[0.6875rem] tracking-[0.12em] text-muted uppercase">
            {{ $kicker }}

            <span class="house-fill h-0.5 min-w-0 flex-1 rounded-full" aria-hidden="true"></span>

            @isset($kickerTrailing)
                <div class="flex shrink-0 flex-wrap items-center justify-end gap-2 font-sans text-sm font-medium normal-case tracking-normal">
                    {{ $kickerTrailing }}
                </div>
            @endisset
        </div>
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
