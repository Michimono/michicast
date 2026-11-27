@props(['href', 'active' => false])

<a
    href="{{ $href }}"
    @if ($active) aria-current="page" @endif
    {{ $attributes->class([
        'rounded-md px-2.5 py-1 text-sm transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent',
        'bg-paper text-ink' => $active,
        'text-muted hover:text-ink' => ! $active,
    ]) }}
>{{ $slot }}</a>
