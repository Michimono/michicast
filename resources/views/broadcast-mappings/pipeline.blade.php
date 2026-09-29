{{-- Continuous vertical pipeline: Source → Mapping → Destination → Preview → Save. --}}
@php
    $mappingReady = ($mappingEditor['ready'] ?? false) === true;
    $sourceMeta = $mappingEditor['source'] ?? null;
    $destinationMeta = $mappingEditor['destination'] ?? null;
    $requiredCount = 0;
    if ($mappingReady && isset($destinationMeta['schema']['fields'])) {
        foreach ($destinationMeta['schema']['fields'] as $field) {
            if (! empty($field['required'])) {
                $requiredCount++;
            }
        }
    }
@endphp

<div class="mx-auto flex w-full max-w-2xl flex-col gap-0" data-mapping-pipeline>
    {{-- SOURCE (compact) --}}
    <section class="border-b border-line pb-6" aria-labelledby="mapping-source-heading">
        <p class="text-[0.6875rem] font-medium tracking-wide text-muted uppercase">Source</p>
        @if ($source)
            <div class="mt-2 flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <h2 id="mapping-source-heading" class="font-display text-xl tracking-tight text-ink">
                        {{ $source->name }}
                    </h2>
                    <p class="mt-0.5 text-sm text-muted">
                        {{ $sourceMeta['type_label'] ?? $source->type }}
                        @if ($mappingReady)
                            · {{ ($sourceMeta['field_count'] ?? 0) > 0 ? 'Sample ready' : 'No sample' }}
                        @endif
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    @if ($mappingReady)
                        <button
                            type="button"
                            class="cursor-pointer rounded-md border border-line px-3 py-1.5 text-sm text-ink hover:border-accent focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-accent"
                            data-mapping-view-source
                            aria-haspopup="dialog"
                        >
                            View fields
                        </button>
                    @endif
                    <x-button href="{{ route('sources.edit', $source) }}" variant="ghost" :paw="false">Manage</x-button>
                </div>
            </div>
        @else
            <div class="mt-3 flex flex-col items-start gap-2" data-pipeline-empty="source">
                <h2 id="mapping-source-heading" class="font-display text-lg tracking-tight text-ink">No source yet</h2>
                <p class="max-w-sm text-sm text-muted">Nothing feeding this broadcast yet. Add a source to start the pipeline.</p>
                @can('create', [App\Models\Source::class, $project])
                    <x-button href="{{ route('broadcast-sources.create', $project) }}" variant="ghost" :paw="false">Add a source</x-button>
                @endcan
            </div>
        @endif
    </section>

    <div class="flex justify-center py-3" aria-hidden="true">
        <span class="h-4 w-px bg-line"></span>
    </div>

    {{-- MAPPING --}}
    <section class="border-b border-line pb-6" aria-labelledby="mapping-heading">
        @include('broadcast-mappings.panel', ['mappingEditor' => $mappingEditor])
    </section>

    <div class="flex justify-center py-3" aria-hidden="true">
        <span class="h-4 w-px bg-line"></span>
    </div>

    {{-- DESTINATION (compact) --}}
    <section class="border-b border-line pb-6" aria-labelledby="mapping-destination-heading">
        <p class="text-[0.6875rem] font-medium tracking-wide text-muted uppercase">Destination</p>
        @if ($destination)
            <div class="mt-2 flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <h2 id="mapping-destination-heading" class="font-display text-xl tracking-tight text-ink">
                        {{ $destination->name }}
                    </h2>
                    <p class="mt-0.5 text-sm text-muted">
                        {{ $destinationMeta['type_label'] ?? $destination->type }}
                        @if ($mappingReady)
                            · {{ $requiredCount }} required
                            · {{ $destinationMeta['schema']['fields'] ? count($destinationMeta['schema']['fields']) : 0 }} fields
                        @endif
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    @if ($mappingReady)
                        <button
                            type="button"
                            class="cursor-pointer rounded-md border border-line px-3 py-1.5 text-sm text-ink hover:border-accent focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-accent"
                            data-mapping-view-destination
                            aria-haspopup="dialog"
                        >
                            View fields
                        </button>
                    @endif
                    <x-button href="{{ route('destinations.edit', $destination) }}" variant="ghost" :paw="false">Manage</x-button>
                </div>
            </div>
        @else
            <div class="mt-3 flex flex-col items-start gap-2" data-pipeline-empty="destination">
                <h2 id="mapping-destination-heading" class="font-display text-lg tracking-tight text-ink">No destination yet</h2>
                <p class="max-w-sm text-sm text-muted">Nowhere to publish yet. Add a destination to finish the pipeline.</p>
                @can('create', [App\Models\Destination::class, $project])
                    <x-button href="{{ route('broadcast-destinations.create', $project) }}" variant="ghost" :paw="false">Add destination</x-button>
                @endcan
            </div>
        @endif
    </section>
</div>
