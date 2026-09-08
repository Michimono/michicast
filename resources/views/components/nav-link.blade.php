@props(['href', 'active' => false])

<a
    href="{{ $href }}"
    @if ($active) aria-current="page" @endif
    {{ $attributes->class([
        'pb-0.5 text-sm transition-colors',
        'house-underline text-ink' => $active,
        'text-muted hover:text-ink' => ! $active,
    ]) }}
>{{ $slot }}</a>
