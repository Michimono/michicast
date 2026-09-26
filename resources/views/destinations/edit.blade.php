<x-layout :title="$destination->name">
    <x-card :heading="$destination->name" kicker="Destination" eared class="max-w-md">
        <div class="flex flex-wrap items-center gap-3">
            <p class="font-mono text-xs text-muted">{{ $typeLabel }}</p>
            <x-destination-status :destination="$destination" />
        </div>

        @if ($destination->last_tested_at)
            <p @class([
                'text-sm',
                'text-state-ok' => $destination->last_test_successful === true,
                'text-state-fail' => $destination->last_test_successful === false,
                'text-muted' => $destination->last_test_successful === null,
            ])>
                Last tested <x-timestamp :value="$destination->last_tested_at" />.
                {{ $destination->last_test_message }}
            </p>
        @else
            <p class="text-sm text-muted">This destination has never been tested.</p>
        @endif

        @if ($testOutcome ?? null)
            <p @class([
                'rounded-md border border-line bg-paper px-3 py-2 text-sm',
                'text-state-ok' => $testOutcome->successful,
                'text-state-fail' => ! $testOutcome->successful,
            ])>
                {{ $testOutcome->message }}
            </p>
        @endif

        @if ($known)
            <form class="flex flex-col gap-4" method="POST" action="{{ route('destinations.update', $destination) }}">
                @csrf
                @method('PATCH')

                <x-input name="name" label="Destination name" :value="$formName" required />

                <x-provider-fields :fields="$fields" :values="$formConfig" />

                <x-credential-fields :fields="$credentialFields" preserving />

                <div class="flex flex-wrap items-center gap-2">
                    <x-button>Save destination</x-button>

                    @can('testConnection', $destination)
                        @if ($supportsConnectionTest)
                            <x-button
                                type="submit"
                                formaction="{{ route('destination-connection-tests.store', $destination) }}"
                                formmethod="POST"
                                variant="ghost"
                                :paw="false"
                            >
                                Send test
                            </x-button>
                        @endif
                    @endcan
                </div>
            </form>
        @else
            <p class="text-sm text-state-fail">
                This destination's type is no longer installed, so its settings cannot be edited.
            </p>
        @endif

        @can('delete', $destination)
            <form
                method="POST"
                action="{{ route('destinations.destroy', $destination) }}"
                onsubmit="return confirm('Delete this destination? The pipeline slot will open so you can add another.')"
            >
                @csrf
                @method('DELETE')

                <x-button variant="ghost" :paw="false">Delete destination</x-button>
            </form>
        @endcan
    </x-card>
</x-layout>
