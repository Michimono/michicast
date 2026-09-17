<x-layout :title="$destination->name">
    <x-card :heading="$destination->name" kicker="Destinations" eared class="max-w-md">
        <div class="flex flex-wrap items-center gap-3">
            <p class="font-mono text-xs text-muted">{{ $typeLabel }}</p>
            <x-destination-status :destination="$destination" />
        </div>

        @if ($destination->last_tested_at)
            <p class="text-sm text-muted">
                Last tested <x-timestamp :value="$destination->last_tested_at" />.
                {{ $destination->last_test_message }}
            </p>
        @endif

        @if ($known)
            <form class="flex flex-col gap-4" method="POST" action="{{ route('destinations.update', $destination) }}">
                @csrf
                @method('PATCH')

                <x-input name="name" label="Destination name" :value="$destination->name" required />

                <x-provider-fields :fields="$fields" :values="$destination->config ?? []" />

                <x-credential-fields :fields="$credentialFields" preserving />

                <x-button>Save destination</x-button>
            </form>
        @else
            <p class="text-sm text-state-fail">
                This destination's type is no longer installed, so its settings cannot be edited.
            </p>
        @endif

        @can('changeStatus', $destination)
            <div class="flex flex-wrap items-center gap-2">
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

                @if ($destination->isArchived())
                    <form method="POST" action="{{ route('archived-destinations.destroy', $destination) }}">
                        @csrf
                        @method('DELETE')

                        <x-button>Restore</x-button>
                    </form>
                @else
                    <form method="POST" action="{{ route('archived-destinations.store', $destination) }}">
                        @csrf

                        <x-button variant="ghost" :paw="false">Archive</x-button>
                    </form>
                @endif
            </div>
        @endcan
    </x-card>
</x-layout>
