@props(['heading', 'message' => null])

<div {{ $attributes->class('flex flex-col items-center gap-3 rounded-lg border border-dashed border-zinc-300 bg-white px-6 py-12 text-center') }}>
    <h2 class="text-base font-semibold">{{ $heading }}</h2>

    @if ($message)
        <p class="max-w-prose text-sm text-zinc-600">{{ $message }}</p>
    @endif

    {{ $slot }}
</div>
