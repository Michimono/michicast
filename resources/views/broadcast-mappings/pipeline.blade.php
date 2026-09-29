{{-- Continuous vertical pipeline: Source → Mapping → Preview → Destination. --}}
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

{{-- Level 0 = page bg-paper. Level 1 = elevated white cards. Mapping is the centerpiece. --}}
<div class="mx-auto flex w-full max-w-2xl flex-col" data-mapping-pipeline>

    {{-- SOURCE (Level 1, compact) --}}
    <x-card kicker="Source" class="!gap-3 !p-5">
        @if ($source)
            <div class="flex flex-wrap items-start justify-between gap-3">
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
                            class="cursor-pointer rounded-md border border-line bg-paper px-3 py-1.5 text-sm text-ink hover:border-accent focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-accent"
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
            <div class="flex flex-col items-start gap-2" data-pipeline-empty="source">
                <h2 id="mapping-source-heading" class="font-display text-lg tracking-tight text-ink">No source yet</h2>
                <p class="max-w-sm text-sm text-muted">Nothing feeding this broadcast yet. Add a source to start the pipeline.</p>
                @can('create', [App\Models\Source::class, $project])
                    <x-button href="{{ route('broadcast-sources.create', $project) }}" variant="ghost" :paw="false">Add a source</x-button>
                @endcan
            </div>
        @endif
    </x-card>

    <div class="flex justify-center py-2" aria-hidden="true">
        <span class="select-none text-base leading-none text-line-strong">↓</span>
    </div>

    {{-- Mapping + Preview share the editor root for JS; each is a Level 1 card. --}}
    @include('broadcast-mappings.panel', ['mappingEditor' => $mappingEditor])

    <div class="flex justify-center py-2" aria-hidden="true">
        <span class="select-none text-base leading-none text-line-strong">↓</span>
    </div>

    {{-- DESTINATION (Level 1, compact) --}}
    <x-card kicker="Destination" class="!gap-3 !p-5">
        @if ($destination)
            <div class="flex flex-wrap items-start justify-between gap-3">
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
                            class="cursor-pointer rounded-md border border-line bg-paper px-3 py-1.5 text-sm text-ink hover:border-accent focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-accent"
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
            <div class="flex flex-col items-start gap-2" data-pipeline-empty="destination">
                <h2 id="mapping-destination-heading" class="font-display text-lg tracking-tight text-ink">No destination yet</h2>
                <p class="max-w-sm text-sm text-muted">Nowhere to publish yet. Add a destination to finish the pipeline.</p>
                @can('create', [App\Models\Destination::class, $project])
                    <x-button href="{{ route('broadcast-destinations.create', $project) }}" variant="ghost" :paw="false">Add destination</x-button>
                @endcan
            </div>
        @endif
    </x-card>
</div>
