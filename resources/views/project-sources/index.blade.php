<x-layout :title="$project->name.' sources'">
    <x-card heading="Sources" :kicker="$project->name" eared>
        <p class="text-sm text-muted">Each source is one place this project reads content from.</p>

        @can('create', [App\Models\Source::class, $project])
            <p>
                <x-button href="{{ route('broadcast-sources.create', $project) }}">New source</x-button>
            </p>
        @endcan

        @if ($sources->isEmpty())
            <x-empty-state
                heading="Nothing to chase yet"
                message="Add a source and this project has somewhere to read from."
            />
        @else
            <ul class="flex flex-col divide-y divide-line border-t border-line">
                @foreach ($sources as $source)
                    <li class="flex flex-wrap items-center justify-between gap-4 py-4">
                        <div class="flex flex-col gap-0.5">
                            <span class="text-sm text-ink">{{ $source->name }}</span>
                            <span class="font-mono text-xs text-muted">{{ $types[$source->type] ?? $source->type }}</span>
                        </div>

                        <div class="flex flex-wrap items-center gap-2">
                            <x-source-status :source="$source" />

                            @can('update', $source)
                                <x-button href="{{ route('sources.edit', $source) }}" variant="ghost" :paw="false">Edit</x-button>
                            @endcan

                            @can('changeStatus', $source)
                                @if ($source->canDisable())
                                    <form method="POST" action="{{ route('enabled-sources.destroy', $source) }}">
                                        @csrf
                                        @method('DELETE')

                                        <x-button variant="ghost" :paw="false">Disable</x-button>
                                    </form>
                                @endif

                                @if ($source->canEnable())
                                    <form method="POST" action="{{ route('enabled-sources.store', $source) }}">
                                        @csrf

                                        <x-button>Enable</x-button>
                                    </form>
                                @endif

                                <form method="POST" action="{{ route('archived-sources.store', $source) }}">
                                    @csrf

                                    <x-button variant="ghost" :paw="false">Archive</x-button>
                                </form>
                            @endcan
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-card>

    @if ($archived->isNotEmpty())
        <x-card heading="Archived sources" kicker="Sources">
            <p class="text-sm text-muted">Archived sources are skipped by every run until they are restored.</p>

            <ul class="flex flex-col divide-y divide-line border-t border-line">
                @foreach ($archived as $source)
                    <li class="flex flex-wrap items-center justify-between gap-4 py-4">
                        <div class="flex flex-col gap-0.5">
                            <span class="text-sm text-ink">{{ $source->name }}</span>
                            <span class="font-mono text-xs text-muted">{{ $types[$source->type] ?? $source->type }}</span>
                        </div>

                        @can('changeStatus', $source)
                            <form method="POST" action="{{ route('archived-sources.destroy', $source) }}">
                                @csrf
                                @method('DELETE')

                                <x-button>Restore</x-button>
                            </form>
                        @endcan
                    </li>
                @endforeach
            </ul>
        </x-card>
    @endif
</x-layout>
