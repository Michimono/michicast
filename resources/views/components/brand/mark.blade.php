@props(['variant' => 'face', 'gradient' => false])

@php
    $marks = [
        'mark' => ['src' => '/images/brand/logo-day-mark.svg', 'aspect' => 'aspect-[20/18]'],
        'face' => ['src' => '/images/brand/logo-day-face.svg', 'aspect' => 'aspect-[37/18]'],
        'cat' => ['src' => '/images/brand/logo-day-full.svg', 'aspect' => 'aspect-[47/52]'],
    ];

    $mark = $marks[$variant] ?? $marks['face'];
@endphp

@if ($gradient)
    <span
        role="img"
        aria-label="{{ config('app.name') }}"
        style="-webkit-mask-image: url('{{ $mark['src'] }}'); -webkit-mask-repeat: no-repeat; -webkit-mask-position: center; -webkit-mask-size: contain; -webkit-mask-source-type: alpha; mask-image: url('{{ $mark['src'] }}'); mask-repeat: no-repeat; mask-position: center; mask-size: contain; mask-mode: alpha"
        {{ $attributes->class(['house-mark block', $mark['aspect']]) }}
    ></span>
@else
    <img src="{{ $mark['src'] }}" alt="{{ config('app.name') }}" {{ $attributes->class('block') }}>
@endif
