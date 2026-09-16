<x-layout :title="$source->name">
    <x-card :heading="$source->name" kicker="Sources" eared class="max-w-md">
        <div class="flex flex-wrap items-center gap-3">
            <p class="font-mono text-xs text-muted">{{ $typeLabel }}</p>
            <x-source-status :source="$source" />
        </div>

        @if ($source->last_tested_at)
            <p class="text-sm text-muted">
                Last tested <x-timestamp :value="$source->last_tested_at" />.
                {{ $source->last_test_message }}
            </p>
        @endif

        @if ($known)
            <form class="flex flex-col gap-4" method="POST" action="{{ route('sources.update', $source) }}">
                @csrf
                @method('PATCH')

                <x-input name="name" label="Source name" :value="$source->name" required />

                <x-provider-fields :fields="$fields" :values="$source->config ?? []" />

                <x-button>Save source</x-button>
            </form>
        @else
            <p class="text-sm text-state-fail">
                This source's type is no longer installed, so its settings cannot be edited.
            </p>
        @endif

        @can('changeStatus', $source)
            <div class="flex flex-wrap items-center gap-2">
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

                @if ($source->isArchived())
                    <form method="POST" action="{{ route('archived-sources.destroy', $source) }}">
                        @csrf
                        @method('DELETE')

                        <x-button>Restore</x-button>
                    </form>
                @else
                    <form method="POST" action="{{ route('archived-sources.store', $source) }}">
                        @csrf

                        <x-button variant="ghost" :paw="false">Archive</x-button>
                    </form>
                @endif
            </div>
        @endcan
    </x-card>
</x-layout>
