<x-layout :title="$project->name.' destinations'">
    <x-card heading="Destinations" :kicker="$project->name" eared>
        <p class="text-sm text-muted">Each destination is one place this project publishes content to.</p>

        @can('create', [App\Models\Destination::class, $project])
            <p>
                <x-button href="{{ route('project-destinations.create', $project) }}">New destination</x-button>
            </p>
        @endcan

        @if ($destinations->isEmpty())
            <x-empty-state
                heading="Nothing to chase yet"
                message="Add a destination and this project has somewhere to publish."
            />
        @else
            <ul class="flex flex-col divide-y divide-line border-t border-line">
                @foreach ($destinations as $destination)
                    <li class="flex flex-wrap items-center justify-between gap-4 py-4">
                        <div class="flex flex-col gap-0.5">
                            <span class="text-sm text-ink">{{ $destination->name }}</span>
                            <span class="font-mono text-xs text-muted">{{ $types[$destination->type] ?? $destination->type }}</span>
                        </div>

                        <div class="flex flex-wrap items-center gap-2">
                            <x-destination-status :destination="$destination" />

                            @can('update', $destination)
                                <x-button href="{{ route('destinations.edit', $destination) }}" variant="ghost" :paw="false">Edit</x-button>
                            @endcan

                            @can('changeStatus', $destination)
                                @if ($destination->canDisable())
                                    <form method="POST" action="{{ route('enabled-destinations.destroy', $destination) }}">
                                        @csrf
                                        @method('DELETE')

                                        <x-button variant="ghost" :paw="false">Disable</x-button>
                                    </form>
                                @endif

                                @if ($destination->canEnable())
                                    <form method="POST" action="{{ route('enabled-destinations.store', $destination) }}">
                                        @csrf

                                        <x-button>Enable</x-button>
                                    </form>
                                @endif

                                <form method="POST" action="{{ route('archived-destinations.store', $destination) }}">
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
        <x-card heading="Archived destinations" kicker="Destinations">
            <p class="text-sm text-muted">Archived destinations are skipped by every run until they are restored.</p>

            <ul class="flex flex-col divide-y divide-line border-t border-line">
                @foreach ($archived as $destination)
                    <li class="flex flex-wrap items-center justify-between gap-4 py-4">
                        <div class="flex flex-col gap-0.5">
                            <span class="text-sm text-ink">{{ $destination->name }}</span>
                            <span class="font-mono text-xs text-muted">{{ $types[$destination->type] ?? $destination->type }}</span>
                        </div>

                        @can('changeStatus', $destination)
                            <form method="POST" action="{{ route('archived-destinations.destroy', $destination) }}">
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
