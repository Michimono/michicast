@props(['text' => null, 'id' => null, 'align' => 'center'])

{{--
    CSS-only tooltip that shows instantly (no delay, no transition) on hover and
    on keyboard focus. The wrapper carries the hover/focus target because
    disabled buttons swallow pointer events in some browsers. Pass text=null
    (e.g. for an enabled control) to render the slot untouched.
--}}
@if (filled($text))
    @php
        $tooltipId = $id ?? 'tooltip-'.\Illuminate\Support\Str::random(8);
        $position = match ($align) {
            'end' => 'right-0',
            'start' => 'left-0',
            default => 'left-1/2 -translate-x-1/2',
        };
    @endphp
    <span tabindex="0" aria-describedby="{{ $tooltipId }}" data-tooltip {{ $attributes->class('group/tooltip relative inline-flex rounded-md focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent') }}>
        {{ $slot }}
        <span id="{{ $tooltipId }}" role="tooltip" data-tooltip-text @class([
            'pointer-events-none absolute top-full z-50 mt-2 hidden w-max max-w-64 rounded-md bg-ink px-3 py-2 text-left text-xs font-medium leading-snug text-ink-contrast shadow-lg',
            'group-hover/tooltip:block group-focus-within/tooltip:block group-focus/tooltip:block',
            $position,
        ])>{{ $text }}</span>
    </span>
@else
    {{ $slot }}
@endif
