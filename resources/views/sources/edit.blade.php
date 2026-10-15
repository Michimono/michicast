<x-layout :title="$source->name">
    @php
        $presentation = App\Support\SourceTypePresentation::for($source->type, $typeLabel);
    @endphp

    <div class="mx-auto flex w-full max-w-3xl min-w-0 flex-col gap-6">
        <section class="flex flex-col items-start gap-3">
            <a
                class="mb-2 inline-flex items-center gap-2 text-sm font-medium text-accent underline-offset-4 hover:underline"
                href="{{ route('broadcasts.show', $source->project) }}"
            ><span aria-hidden="true">&larr;</span> Back to broadcast</a>

            <span class="max-w-full truncate rounded-md bg-accent/10 px-2.5 py-1 font-mono text-[0.6875rem] tracking-[0.12em] text-accent uppercase">{{ $source->project->name }} &middot; Source</span>
            <h1 class="font-display text-4xl tracking-tight break-words text-ink">{{ $source->name }}</h1>
            <p class="max-w-prose text-base text-muted">Update this source's settings and test that it still reaches its content.</p>
        </section>

        <section class="relative house-edge min-w-0 rounded-card border bg-paper-raised" aria-labelledby="source-settings-heading">
            <div class="flex flex-col gap-4 border-b border-line px-5 py-5 sm:flex-row sm:items-start sm:justify-between sm:px-8">
                <div class="flex min-w-0 items-center gap-3">
                    <span class="grid size-10 shrink-0 place-items-center rounded-lg bg-accent/10 font-mono text-xs font-semibold text-accent" aria-hidden="true">{{ $presentation['icon'] }}</span>
                    <div class="min-w-0">
                        <h2 id="source-settings-heading" class="font-display text-xl tracking-tight text-ink">{{ $presentation['name'] }} settings</h2>
                        <p class="text-sm text-muted" data-source-type-label>{{ $typeLabel }} &middot; the type can't change after creation</p>
                    </div>
                </div>

                @if ($source->last_tested_at)
                    <span @class([
                        'shrink-0 self-start rounded-md px-2 py-1 font-mono text-[0.625rem] tracking-[0.12em] uppercase',
                        'bg-state-ok/10 text-state-ok' => $source->last_test_successful === true,
                        'bg-state-fail/10 text-state-fail' => $source->last_test_successful === false,
                        'bg-ink/5 text-muted' => $source->last_test_successful === null,
                    ])>{{ $source->last_test_successful === true ? 'Test passed' : ($source->last_test_successful === false ? 'Test failed' : 'Tested') }}</span>
                @else
                    <span class="shrink-0 self-start rounded-md bg-ink/5 px-2 py-1 font-mono text-[0.625rem] tracking-[0.12em] text-muted uppercase">Not tested</span>
                @endif
            </div>

            <div class="flex flex-col gap-6 px-5 py-6 sm:px-8">
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
                        'rounded-md border px-3 py-2 text-sm',
                        'border-state-ok/30 bg-state-ok/5 text-state-ok' => $testOutcome->successful,
                        'border-state-fail/30 bg-state-fail/5 text-state-fail' => ! $testOutcome->successful,
                    ]) role="status">
                        {{ $testOutcome->message }}
                    </p>
                @endif

                @if ($known)
                    <form class="flex flex-col gap-6" method="POST" action="{{ route('sources.update', $source) }}">
                        @csrf
                        @method('PATCH')

                        <div class="grid gap-5 sm:grid-cols-2">
                            <div class="min-w-0 sm:col-span-2">
                                <x-input name="name" label="Source name" size="lg" :value="$formName" required />
                            </div>

                            <x-provider-fields :fields="$fields" :values="$formConfig" size="lg" grid />
                        </div>

                        <div class="flex flex-col-reverse gap-3 border-t border-line pt-6 sm:flex-row sm:justify-end">
                            @can('testConnection', $source)
                                @if ($supportsConnectionTest)
                                    <x-button
                                        type="submit"
                                        formaction="{{ route('source-connection-tests.store', $source) }}"
                                        formmethod="POST"
                                        variant="ghost"
                                        :paw="false"
                                    >
                                        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m7 5 11 7-11 7V5Z" /></svg>
                                        Test connection
                                    </x-button>
                                @endif
                            @endcan

                            <x-button class="sm:min-w-44" :paw="false">
                                Save source
                                <x-icons.arrow-right class="size-4" />
                            </x-button>
                        </div>
                    </form>
                @else
                    <p class="text-sm text-state-fail">
                        This source's type is no longer installed, so its settings cannot be edited.
                    </p>
                @endif
            </div>
        </section>

        @can('delete', $source)
            <section class="flex flex-col gap-3 rounded-card border border-line bg-paper-raised px-5 py-5 sm:flex-row sm:items-center sm:justify-between sm:px-8" aria-labelledby="source-delete-heading">
                <div>
                    <h2 id="source-delete-heading" class="text-base font-medium text-ink">Delete source</h2>
                    <p class="text-sm text-muted">The pipeline slot opens so you can add another source.</p>
                </div>

                <form
                    method="POST"
                    action="{{ route('sources.destroy', $source) }}"
                    data-confirm="Delete this source? The pipeline slot will open so you can add another."
                >
                    @csrf
                    @method('DELETE')
                    <input type="hidden" name="confirmation" value="1">

                    <x-button variant="ghost" :paw="false" class="w-full sm:w-auto">Delete source</x-button>
                </form>
            </section>
        @endcan
    </div>
</x-layout>
