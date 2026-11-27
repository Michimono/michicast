@props(['heading' => null, 'kicker' => null, 'index' => null, 'eared' => false, 'highlighted' => false])

{{--
    A raised sheet: the surface application content sits on, lifted off the
    page by the same ink-tinted shadow the public site uses. The kicker is
    set as it is there, a mono label beside a thin house rule; $index numbers
    it when the card is one step of a sequence, and is left off otherwise.
--}}
<section {{ $attributes->class([
    'relative flex flex-col gap-3 rounded-card bg-paper-raised p-6 shadow-float sm:p-8',
    'border border-line' => ! $highlighted,
    'border house-edge' => $highlighted,
]) }}>
    @if ($eared || $highlighted)
        <x-card-ears :rainbow="$highlighted" />
    @endif

    @if ($kicker)
        {{-- div, not p: kickerTrailing may include interactive controls; browsers close <p> before <form>. --}}
        <div class="flex items-center gap-3 font-mono text-[0.6875rem] tracking-[0.14em] text-muted uppercase">
            @if ($index)
                <span class="text-ink">{{ $index }}</span>
            @endif

            <span class="house-fill h-px w-8 shrink-0" aria-hidden="true"></span>

            <span class="min-w-0 truncate">{{ $kicker }}</span>

            @isset($kickerTrailing)
                <div class="ml-auto flex shrink-0 flex-wrap items-center justify-end gap-2 font-sans text-sm font-medium normal-case tracking-normal">
                    {{ $kickerTrailing }}
                </div>
            @endisset
        </div>
    @endif

    @if ($heading || isset($actions))
        <div class="flex flex-wrap items-start justify-between gap-3">
            @if ($heading)
                <h2 class="font-display text-2xl leading-tight tracking-tight sm:text-3xl">{{ $heading }}</h2>
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
