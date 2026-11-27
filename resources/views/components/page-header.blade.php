@props(['heading', 'kicker' => null, 'lead' => null, 'back' => null, 'backLabel' => 'Back'])

{{--
    The heading of an application page, set the way the public site sets a
    section: a mono kicker beside a thin house rule, then a display headline.
    One per page, above the sheets its content sits on, and the owner of the
    page's only <h1>. $kicker and $lead accept a prop or a named slot; slots
    keep entities such as &middot;. The actions slot holds the page's own
    controls, level with the headline. $back is the way out of a focused
    page such as a form.
--}}
<header {{ $attributes->class(['flex flex-wrap items-end justify-between gap-x-10 gap-y-5']) }}>
    <div class="flex min-w-0 flex-col items-start">
        @if ($back)
            <a
                class="mb-6 inline-flex items-center gap-2 rounded-sm text-sm font-medium text-accent underline-offset-4 hover:underline focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-accent"
                href="{{ $back }}"
            ><span aria-hidden="true">&larr;</span> {{ $backLabel }}</a>
        @endif

        @if ($kicker)
            <p class="mb-4 flex max-w-full items-center gap-3 font-mono text-[0.6875rem] tracking-[0.14em] text-muted uppercase">
                <span class="house-fill h-px w-8 shrink-0" aria-hidden="true"></span>
                <span class="min-w-0 truncate">{{ $kicker }}</span>
            </p>
        @endif

        <h1 class="max-w-full font-display text-4xl leading-none tracking-tight text-balance break-words sm:text-5xl">{{ $heading }}</h1>

        @if ($lead)
            <p class="mt-4 max-w-prose text-base leading-7 text-muted">{{ $lead }}</p>
        @endif
    </div>

    @isset($actions)
        <div class="flex flex-wrap items-center gap-2">
            {{ $actions }}
        </div>
    @endisset
</header>
