@props(['heading' => null])

<section {{ $attributes->class('flex flex-col gap-3 rounded-lg border border-zinc-200 bg-white p-6') }}>
    @if ($heading)
        <h2 class="text-base font-semibold">{{ $heading }}</h2>
    @endif

    {{ $slot }}
</section>
