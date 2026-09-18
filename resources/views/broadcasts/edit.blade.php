<x-layout :title="$broadcast->name">
    <x-card :heading="$broadcast->name" kicker="Broadcasts" eared class="max-w-md">
        <div class="flex flex-wrap items-center gap-3">
            <x-broadcast-status :broadcast="$broadcast" />
        </div>

        <form class="flex flex-col gap-4" method="POST" action="{{ route('broadcasts.update', $broadcast) }}">
            @csrf
            @method('PATCH')

            <x-input name="name" label="Broadcast name" :value="$broadcast->name" required />

            <x-select name="source_id" label="Source" :options="$sources" :value="$broadcast->source_id" required />

            <x-select name="destination_id" label="Destination" :options="$destinations" :value="$broadcast->destination_id" required />

            <x-button>Save broadcast</x-button>
        </form>

        <p class="flex flex-wrap items-center gap-2">
            <x-button href="{{ route('broadcast-filters.edit', $broadcast) }}" variant="ghost" :paw="false">Filters</x-button>
            <x-button href="{{ route('broadcast-transforms.edit', $broadcast) }}" variant="ghost" :paw="false">Transform</x-button>
        </p>

        @can('preview', $broadcast)
            <form method="POST" action="{{ route('content-previews.store', $broadcast) }}">
                @csrf
                <x-button variant="ghost" :paw="false">Preview</x-button>
            </form>
        @endcan

        @can('changeStatus', $broadcast)
            <div class="flex flex-wrap items-center gap-2">
                @if ($broadcast->canDisable())
                    <form method="POST" action="{{ route('enabled-broadcasts.destroy', $broadcast) }}">
                        @csrf
                        @method('DELETE')

                        <x-button variant="ghost" :paw="false">Disable</x-button>
                    </form>
                @endif

                @if ($broadcast->canEnable())
                    <form method="POST" action="{{ route('enabled-broadcasts.store', $broadcast) }}">
                        @csrf

                        <x-button>Enable</x-button>
                    </form>
                @endif

                @if ($broadcast->isArchived())
                    <form method="POST" action="{{ route('archived-broadcasts.destroy', $broadcast) }}">
                        @csrf
                        @method('DELETE')

                        <x-button>Restore</x-button>
                    </form>
                @else
                    <form method="POST" action="{{ route('archived-broadcasts.store', $broadcast) }}">
                        @csrf

                        <x-button variant="ghost" :paw="false">Archive</x-button>
                    </form>
                @endif
            </div>
        @endcan
    </x-card>

    @if ($previewError ?? null)
        <x-card heading="Preview" kicker="Would publish" class="max-w-2xl">
            <p class="rounded-md border border-line bg-paper px-3 py-2 text-sm text-state-fail">
                {{ $previewError }}
            </p>
        </x-card>
    @elseif ($preview ?? null)
        <x-card heading="Preview" kicker="Would publish" class="max-w-2xl">
            <p class="font-mono text-xs text-muted">
                Found {{ $preview->found }}
                · would publish {{ $preview->wouldPublish }}
                · would skip {{ $preview->wouldSkip }}
            </p>

            @if ($preview->items === [])
                <p class="text-sm text-muted">The source returned no recent items to preview.</p>
            @else
                <ul class="flex flex-col divide-y divide-line border-t border-line">
                    @foreach ($preview->items as $item)
                        <li class="flex flex-col gap-2 py-4">
                            <div class="flex flex-wrap items-baseline justify-between gap-2">
                                <span class="text-sm font-medium text-ink">{{ $item->title }}</span>
                                <span @class([
                                    'font-mono text-xs',
                                    'text-state-ok' => $item->wouldPublish(),
                                    'text-state-skip' => ! $item->wouldPublish(),
                                ])>
                                    {{ $item->wouldPublish() ? 'Would publish' : 'Would skip' }}
                                </span>
                            </div>

                            @if ($item->canonicalUrl)
                                <a class="text-xs text-accent hover:underline" href="{{ $item->canonicalUrl }}" rel="noopener noreferrer" target="_blank">
                                    {{ $item->canonicalUrl }}
                                </a>
                            @endif

                            @if ($item->body)
                                <div>
                                    <p class="text-xs font-medium tracking-wide text-muted uppercase">Source</p>
                                    <p class="whitespace-pre-wrap text-sm text-ink">{{ $item->body }}</p>
                                </div>
                            @endif

                            @if ($item->wouldPublish() && $item->rendered)
                                <div>
                                    <p class="text-xs font-medium tracking-wide text-muted uppercase">Rendered</p>
                                    <p class="whitespace-pre-wrap text-sm text-ink">{{ $item->rendered }}</p>
                                </div>
                            @endif

                            @if ($item->skipReason)
                                <p class="text-sm text-muted">{{ $item->skipReason }}</p>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-card>
    @endif
</x-layout>
