@props(['heading', 'message' => null, 'eared' => false])

<div {{ $attributes->class(['relative house-edge flex flex-col items-center gap-3 rounded-card border px-6 py-12 text-center']) }}>
    @if ($eared)
        <x-card-ears />
    @endif

    <x-brand.mark variant="cat" gradient class="h-16" />

    <h2 class="font-display text-2xl leading-tight tracking-tight text-balance sm:text-3xl">{{ $heading }}</h2>

    @if ($message)
        <p class="max-w-prose text-sm text-muted">{{ $message }}</p>
    @endif

    {{ $slot }}
</div>