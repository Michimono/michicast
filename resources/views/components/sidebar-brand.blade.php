@props(['href', 'label'])

{{--
    Top lockup for a floating rail. The square mark (no whiskers) shows while
    the rail is icon-only; the face mark (with whiskers) and the label show
    when the rail is wide enough for text — on the mobile drawer and from `xl`.
--}}
<a
    href="{{ $href }}"
    {{ $attributes->class([
        'flex items-center gap-2.5 py-1 max-lg:justify-start max-lg:px-1 lg:justify-center xl:justify-start xl:px-1',
    ]) }}
>
    <x-brand.mark variant="mark" class="h-7 max-lg:hidden xl:hidden" />
    <x-brand.mark variant="face" class="hidden h-6 max-lg:block xl:block" />

    <span @class([
        'truncate font-display text-base tracking-tight',
        'lg:sr-only xl:not-sr-only',
    ])>{{ $label }}</span>
</a>
