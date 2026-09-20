@props(['href', 'label'])

{{--
    Context title for a floating rail. The sidebar owns the current resource
    (organization, settings, …); it never repeats the application mark. The
    label stays visible when the rail is wide, and truncates to a compact
    identity when the rail is icon-only between `lg` and `xl`.
--}}
<a
    href="{{ $href }}"
    title="{{ $label }}"
    {{ $attributes->class([
        'block truncate px-1 py-2 font-display text-base tracking-tight max-lg:px-2 lg:text-center xl:px-2 xl:text-left',
    ]) }}
>{{ $label }}</a>
