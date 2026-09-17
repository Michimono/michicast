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
</x-layout>
