@props(['variant' => 'face', 'gradient' => false])

@php
    $marks = [
        'mark' => ['src' => '/images/brand/logo-day-mark.svg', 'aspect' => 'aspect-[20/18]'],
        'face' => ['src' => '/images/brand/logo-day-face.svg', 'aspect' => 'aspect-[37/18]'],
        'cat' => ['src' => '/images/brand/logo-day-full.svg', 'aspect' => 'aspect-[47/52]'],
    ];

    $variant = array_key_exists($variant, $marks) ? $variant : 'face';
    $mark = $marks[$variant];
@endphp

@if ($gradient)
    <span
        role="img"
        aria-label="{{ config('app.name') }}"
        {{ $attributes->class(['house-mark block', 'house-mark-'.$variant, $mark['aspect']]) }}
    ></span>
@else
    <img src="{{ $mark['src'] }}" alt="{{ config('app.name') }}" {{ $attributes->class('block') }}>
@endif