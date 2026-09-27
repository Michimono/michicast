<x-layout title="New broadcast">
    <x-card heading="New broadcast" :kicker="$project->name" eared class="max-w-md">
        <p class="text-sm text-ink">
            Pick a source and a destination in this project. Filters and transforms come later.
        </p>

        @if ($sources === [] || $destinations === [])
            <x-empty-state
                heading="Need both ends first"
                message="Add at least one source and one destination before you can create a broadcast."
            />

            <div class="flex flex-wrap items-center gap-2">
                @can('create', [App\Models\Source::class, $project])
                    <x-button href="{{ route('broadcast-sources.create', $project) }}" variant="ghost" :paw="false">Add source</x-button>
                @endcan

                @can('create', [App\Models\Destination::class, $project])
                    <x-button href="{{ route('broadcast-destinations.create', $project) }}" variant="ghost" :paw="false">Add destination</x-button>
                @endcan
            </div>
        @else
            <form class="flex flex-col gap-4" method="POST" action="{{ route('broadcast-pipelines.store', $project) }}">
                @csrf

                <x-input name="name" label="Broadcast name" autofocus required />

                <x-select name="source_id" label="Source" :options="$sources" required />

                <x-select name="destination_id" label="Destination" :options="$destinations" required />

                <x-button>Add broadcast</x-button>
            </form>
        @endif
    </x-card>
</x-layout>
