@props(['href', 'label'])

{{--
    Context title for a floating rail. The sidebar owns the current resource
    (organization, settings, …); it never repeats the application mark. The
    label stays visible when the rail is wide. Between `lg` and `xl` the rail
    is icon-only, so the title becomes the information icon and the resource
    name is a tooltip on hover or keyboard focus.
--}}
<a
    href="{{ $href }}"
    {{ $attributes->class([
        'group relative flex items-center rounded-md px-1 py-2 max-lg:px-2 lg:justify-center xl:justify-start xl:px-2',
    ]) }}
>
    <x-icons.info class="hidden shrink-0 lg:block xl:hidden" />

    <span @class([
        'truncate font-display text-base tracking-tight',
        'lg:pointer-events-none lg:absolute lg:left-full lg:z-10 lg:ml-2 lg:w-max lg:overflow-visible lg:rounded-md lg:border lg:border-line lg:bg-paper-raised lg:px-2 lg:py-1 lg:font-sans lg:text-sm lg:tracking-normal lg:text-clip lg:text-ink lg:opacity-0 lg:transition-opacity',
        'lg:group-hover:opacity-100 lg:group-focus-visible:opacity-100',
        'xl:pointer-events-auto xl:static xl:ml-0 xl:overflow-hidden xl:rounded-none xl:border-0 xl:bg-transparent xl:px-0 xl:py-0 xl:font-display xl:text-base xl:tracking-tight xl:text-ellipsis xl:opacity-100',
    ])>{{ $label }}</span>
</a>
