@props(['href', 'active' => false])

{{--
    One row of the organization rail. The label is always in the document, so
    the link keeps its accessible name at every width; between `lg` and `xl`
    the rail is collapsed and the label is shown as a tooltip on hover or
    keyboard focus instead of inline.
--}}
<a
    href="{{ $href }}"
    @if ($active) aria-current="page" @endif
    {{ $attributes->class([
        'group relative flex items-center gap-3 rounded-md px-3 py-2 transition-colors lg:justify-center xl:justify-start',
        'bg-paper text-ink' => $active,
        'text-muted hover:bg-paper hover:text-ink' => ! $active,
    ]) }}
>
    {{ $icon }}

    <span @class([
        'text-sm',
        'lg:pointer-events-none lg:absolute lg:left-full lg:z-10 lg:ml-2 lg:w-max lg:rounded-md lg:border lg:border-line lg:bg-paper-raised lg:px-2 lg:py-1 lg:text-ink lg:opacity-0 lg:transition-opacity',
        'lg:group-hover:opacity-100 lg:group-focus-visible:opacity-100',
        'xl:pointer-events-auto xl:static xl:ml-0 xl:rounded-none xl:border-0 xl:bg-transparent xl:px-0 xl:py-0 xl:opacity-100',
    ])>{{ $slot }}</span>
</a>
