<x-layout :title="$project->name.' broadcasts'">
    <x-card heading="Broadcasts" :kicker="$project->name" eared>
        <p class="text-sm text-muted">Each broadcast is one source feeding one destination.</p>

        @can('create', [App\Models\Broadcast::class, $project])
            <p>
                <x-button href="{{ route('broadcast-pipelines.create', $project) }}">New broadcast</x-button>
            </p>
        @endcan

        @if ($broadcasts->isEmpty())
            <x-empty-state
                heading="Nothing to send yet"
                message="Connect a source to a destination and this project has a route to publish along."
            />
        @else
            <ul class="flex flex-col divide-y divide-line border-t border-line">
                @foreach ($broadcasts as $broadcast)
                    <li class="flex flex-wrap items-center justify-between gap-4 py-4">
                        <div class="flex flex-col gap-0.5">
                            <span class="text-sm text-ink">{{ $broadcast->name }}</span>
                            <span class="font-mono text-xs text-muted">
                                {{ $broadcast->source->name }} → {{ $broadcast->destination->name }}
                            </span>
                        </div>

                        <div class="flex flex-wrap items-center gap-2">
                            <x-broadcast-status :broadcast="$broadcast" />

                            @can('view', $project)
                                <x-button href="{{ route('broadcast-deliveries.index', $broadcast) }}" variant="ghost" :paw="false">Deliveries</x-button>
                            @endcan

                            @can('update', $broadcast)
                                <x-button href="{{ route('flows.edit', $broadcast) }}" variant="ghost" :paw="false">Edit</x-button>
                            @endcan

                            @can('changeStatus', $broadcast)
                                @if ($broadcast->canDisable())
                                    <form method="POST" action="{{ route('enabled-flows.destroy', $broadcast) }}">
                                        @csrf
                                        @method('DELETE')

                                        <x-button variant="ghost" :paw="false">Disable</x-button>
                                    </form>
                                @endif

                                @if ($broadcast->canEnable())
                                    <form method="POST" action="{{ route('enabled-flows.store', $broadcast) }}">
                                        @csrf

                                        <x-button>Enable</x-button>
                                    </form>
                                @endif

                                <form method="POST" action="{{ route('archived-flows.store', $broadcast) }}">
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
        <x-card heading="Archived broadcasts" kicker="Broadcasts">
            <p class="text-sm text-muted">Archived broadcasts are skipped by every run until they are restored.</p>

            <ul class="flex flex-col divide-y divide-line border-t border-line">
                @foreach ($archived as $broadcast)
                    <li class="flex flex-wrap items-center justify-between gap-4 py-4">
                        <div class="flex flex-col gap-0.5">
                            <span class="text-sm text-ink">{{ $broadcast->name }}</span>
                            <span class="font-mono text-xs text-muted">
                                {{ $broadcast->source->name }} → {{ $broadcast->destination->name }}
                            </span>
                        </div>

                        @can('changeStatus', $broadcast)
                            <form method="POST" action="{{ route('archived-flows.destroy', $broadcast) }}">
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
