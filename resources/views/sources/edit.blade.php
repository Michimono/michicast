<x-layout :title="$source->name">
    <x-card :heading="$source->name" kicker="Source" eared class="max-w-md">
        <div class="flex flex-wrap items-center gap-3">
            <p class="font-mono text-xs text-muted">{{ $typeLabel }}</p>
            <x-source-status :source="$source" />
        </div>

        @if ($source->last_tested_at)
            <p @class([
                'text-sm',
                'text-state-ok' => $source->last_test_successful === true,
                'text-state-fail' => $source->last_test_successful === false,
                'text-muted' => $source->last_test_successful === null,
            ])>
                Last tested <x-timestamp :value="$source->last_tested_at" />.
                {{ $source->last_test_message }}
            </p>
        @else
            <p class="text-sm text-muted">This source has never been tested.</p>
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
            <form class="flex flex-col gap-4" method="POST" action="{{ route('sources.update', $source) }}">
                @csrf
                @method('PATCH')

                <x-input name="name" label="Source name" :value="$formName" required />

                <x-provider-fields :fields="$fields" :values="$formConfig" />

                <div class="flex flex-wrap items-center gap-2">
                    <x-button>Save source</x-button>

                    @can('testConnection', $source)
                        @if ($supportsConnectionTest)
                            <x-button
                                type="submit"
                                formaction="{{ route('source-connection-tests.store', $source) }}"
                                formmethod="POST"
                                variant="ghost"
                                :paw="false"
                            >
                                Test connection
                            </x-button>
                        @endif
                    @endcan
                </div>
            </form>
        @else
            <p class="text-sm text-state-fail">
                This source's type is no longer installed, so its settings cannot be edited.
            </p>
        @endif

        @can('delete', $source)
            <form
                method="POST"
                action="{{ route('sources.destroy', $source) }}"
                onsubmit="return confirm('Delete this source? The pipeline slot will open so you can add another.')"
            >
                @csrf
                @method('DELETE')

                <x-button variant="ghost" :paw="false">Delete source</x-button>
            </form>
        @endcan
    </x-card>
</x-layout>
