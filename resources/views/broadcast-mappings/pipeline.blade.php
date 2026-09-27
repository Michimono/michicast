{{-- Vertical Source → Mapping → Destination pipeline for the broadcast overview. --}}
@php
    $mappingReady = ($mappingEditor['ready'] ?? false) === true;
    $sourceMeta = $mappingEditor['source'] ?? null;
    $destinationMeta = $mappingEditor['destination'] ?? null;
@endphp

<div class="flex flex-col gap-6">
    {{-- SOURCE --}}
    <section class="flex flex-col gap-3">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <p class="font-mono text-[0.6875rem] tracking-wide text-muted uppercase">Source</p>
                @if ($source)
                    <p class="mt-1 font-display text-xl tracking-tight text-ink">
                        {{ $sourceMeta['type_label'] ?? $source->type }} — {{ $source->name }}
                    </p>
                    @if ($mappingReady)
                        <p class="mt-1 text-sm text-muted">
                            Sample data available · {{ $sourceMeta['field_count'] ?? 0 }} fields
                        </p>
                    @endif
                @else
                    <p class="mt-2 text-sm text-muted">Nothing feeding this broadcast yet.</p>
                @endif
            </div>
            <div class="flex flex-wrap gap-2">
                @if ($source)
                    <x-button href="{{ route('sources.edit', $source) }}" variant="ghost" :paw="false">Manage</x-button>
                    @if ($mappingReady)
                        <button type="button" class="rounded-md border border-line px-3 py-2 text-sm text-ink hover:border-accent" data-mapping-view-source>
                            View source data
                        </button>
                        <button type="button" class="rounded-md border border-line px-3 py-2 text-sm text-muted hover:text-ink" data-mapping-refresh-sample>
                            Refresh sample
                        </button>
                    @endif
                @else
                    @can('create', [App\Models\Source::class, $project])
                        <x-button href="{{ route('broadcast-sources.create', $project) }}" variant="ghost" :paw="false">Add a source</x-button>
                    @endcan
                @endif
            </div>
        </div>
    </section>

    <div class="flex justify-center text-muted" aria-hidden="true">
        <x-icons.arrow-right class="size-5 rotate-90" />
    </div>

    {{-- MAPPING --}}
    @include('broadcast-mappings.panel', ['mappingEditor' => $mappingEditor])

    <div class="flex justify-center text-muted" aria-hidden="true">
        <x-icons.arrow-right class="size-5 rotate-90" />
    </div>

    {{-- DESTINATION --}}
    <section class="flex flex-col gap-3">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <p class="font-mono text-[0.6875rem] tracking-wide text-muted uppercase">Destination</p>
                @if ($destination)
                    <p class="mt-1 font-display text-xl tracking-tight text-ink">
                        {{ $destinationMeta['type_label'] ?? $destination->type }} — {{ $destination->name }}
                    </p>
                    @if ($mappingReady && isset($destinationMeta['schema']['fields']))
                        <div class="mt-2 flex flex-col gap-1">
                            <p class="text-xs font-medium tracking-wide text-muted uppercase">Expected fields</p>
                            @foreach ($destinationMeta['schema']['fields'] as $field)
                                <p class="text-sm text-ink">
                                    <span class="font-medium">{{ $field['label'] }}</span>
                                    <span class="text-muted"> · {{ $field['type'] }} · {{ !empty($field['required']) ? 'required' : 'optional' }}</span>
                                </p>
                            @endforeach
                        </div>
                    @endif
                @else
                    <p class="mt-2 text-sm text-muted">Nowhere to publish yet.</p>
                @endif
            </div>
            <div class="flex flex-wrap gap-2">
                @if ($destination)
                    <x-button href="{{ route('destinations.edit', $destination) }}" variant="ghost" :paw="false">Manage</x-button>
                    @if ($mappingReady)
                        <button type="button" class="rounded-md border border-line px-3 py-2 text-sm text-ink hover:border-accent" data-mapping-view-destination>
                            View destination fields
                        </button>
                    @endif
                @else
                    @can('create', [App\Models\Destination::class, $project])
                        <x-button href="{{ route('broadcast-destinations.create', $project) }}" variant="ghost" :paw="false">Add destination</x-button>
                    @endcan
                @endif
            </div>
        </div>
    </section>
</div>
