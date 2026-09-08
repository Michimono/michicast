@props(['heading', 'message' => null])

<div {{ $attributes->class('house-edge flex flex-col items-center gap-3 rounded-card border px-6 py-12 text-center') }}>
    <x-brand.mark variant="cat" gradient class="h-16" />

    <h2 class="font-display text-xl tracking-tight">{{ $heading }}</h2>

    @if ($message)
        <p class="max-w-prose text-sm text-muted">{{ $message }}</p>
    @endif

    {{ $slot }}
</div>
