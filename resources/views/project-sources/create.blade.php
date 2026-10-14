<x-layout title="New source">
    @php
        $formName = $formName ?? old('name', '');
        $formConfig = old('config', $formConfig ?? []);
        $previewEntry = $previewEntry ?? null;
        $previewError = $previewError ?? null;
        $current = $typeGroups[$type];
        $status = match (true) {
            $previewError !== null => ['label' => 'Needs attention', 'class' => 'bg-state-fail/10 text-state-fail'],
            $previewEntry !== null => ['label' => 'Test complete', 'class' => 'bg-state-ok/10 text-state-ok'],
            default => ['label' => 'Not tested', 'class' => 'bg-ink/5 text-muted'],
        };
    @endphp

    <div class="mx-auto flex w-full max-w-6xl min-w-0 flex-col gap-6">
        <section class="flex flex-col items-start gap-3">
            <a
                class="mb-2 inline-flex items-center gap-2 text-sm font-medium text-accent underline-offset-4 hover:underline"
                href="{{ route('broadcasts.show', $project) }}"
            ><span aria-hidden="true">&larr;</span> Back to {{ $project->name }}</a>

            <span class="max-w-full truncate rounded-md bg-accent/10 px-2.5 py-1 font-mono text-[0.6875rem] tracking-[0.12em] text-accent uppercase">{{ $project->name }} &middot; Source</span>
            <h1 class="font-display text-4xl tracking-tight text-ink">New source</h1>
            <p class="max-w-prose text-base text-muted">
                Choose a source type, fill in its settings, and test the feed before you add it.
            </p>
        </section>

        <div class="grid min-w-0 gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(20rem,0.85fr)] lg:items-start">
            <section class="relative house-edge min-w-0 rounded-card border bg-paper-raised" aria-labelledby="source-config-heading">
                <div class="border-b border-line px-5 py-5 sm:px-8">
                    <h2 id="source-config-heading" class="font-display text-xl tracking-tight text-ink">Source configuration</h2>
                    <p class="mt-1 text-sm text-muted">Choose where content for this broadcast is collected from.</p>
                </div>

                {{--
                    Every offered type's fields render in their own disabled fieldset; picking a
                    tile enables just that group (app.js, data-source-form) so only its fields
                    post. Without JS, "Show settings" reloads the page for the chosen type.
                --}}
                <form
                    class="flex flex-col gap-6 px-5 py-6 sm:px-8"
                    method="POST"
                    action="{{ route('broadcast-sources.store', $project) }}"
                    data-source-form
                >
                    @csrf

                    <fieldset class="m-0 flex min-w-0 flex-col gap-3 border-0 p-0">
                        <legend class="mb-3 flex w-full items-center justify-between gap-3 text-sm font-medium text-ink">
                            <span>Source type</span>
                            <span class="rounded-md bg-ink/5 px-2 py-1 font-mono text-[0.625rem] tracking-[0.12em] text-muted uppercase">Step 1 of 2</span>
                        </legend>

                        <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                            @foreach ($typeGroups as $option => $group)
                                <label class="group/tile relative block cursor-pointer" data-source-type-tile>
                                    <input
                                        type="radio"
                                        name="type"
                                        value="{{ $option }}"
                                        class="peer sr-only"
                                        data-source-type-option
                                        @checked($option === $type)
                                    >
                                    <span class="flex h-full min-h-24 flex-col items-center justify-center gap-1 rounded-lg border border-line bg-paper-raised px-2 py-3 text-center transition-colors hover:border-line-strong peer-checked:border-accent peer-checked:bg-accent/5 peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-accent">
                                        <span class="mb-1 grid size-9 place-items-center rounded-md bg-ink/5 font-mono text-xs font-semibold text-muted group-has-checked/tile:bg-accent/10 group-has-checked/tile:text-accent" aria-hidden="true">{{ $group['presentation']['icon'] }}</span>
                                        <span class="text-sm font-medium text-ink">{{ $group['presentation']['name'] }}</span>
                                        @if ($group['presentation']['descriptor'] !== '')
                                            <span class="text-xs text-muted">{{ $group['presentation']['descriptor'] }}</span>
                                        @endif
                                    </span>
                                </label>
                            @endforeach
                        </div>

                        {{-- No-JS fallback only: with JS the tiles swap the field groups in place. --}}
                        <noscript>
                            <x-button
                                type="submit"
                                variant="ghost"
                                :paw="false"
                                formaction="{{ route('broadcast-sources.create', $project) }}"
                                formmethod="GET"
                                formnovalidate
                                data-source-type-reload
                            >Show settings</x-button>
                        </noscript>
                    </fieldset>

                    <div class="flex flex-col gap-5 border-t border-line pt-6">
                        <div class="flex items-center justify-between gap-3">
                            <h3 class="flex min-w-0 items-center gap-2.5 text-base font-medium text-ink">
                                <span class="grid size-8 shrink-0 place-items-center rounded-md bg-accent/10 font-mono text-xs font-semibold text-accent" aria-hidden="true" data-source-settings-icon>{{ $current['presentation']['icon'] }}</span>
                                <span data-source-settings-heading>{{ $current['presentation']['name'] }} settings</span>
                            </h3>
                            <span class="shrink-0 rounded-md bg-ink/5 px-2 py-1 font-mono text-[0.625rem] tracking-[0.12em] text-muted uppercase">Step 2 of 2</span>
                        </div>

                        <x-input
                            name="name"
                            label="Name"
                            size="lg"
                            :value="$formName"
                            placeholder="e.g. Pokémon News"
                            hint="Shown on the broadcast overview."
                            required
                        />

                        @foreach ($typeGroups as $option => $group)
                            <fieldset
                                class="m-0 min-w-0 border-0 p-0"
                                data-source-type-fields="{{ $option }}"
                                data-source-type-heading="{{ $group['presentation']['name'] }} settings"
                                data-source-type-icon="{{ $group['presentation']['icon'] }}"
                                data-source-type-preview="{{ $group['supportsPreview'] ? 'yes' : 'no' }}"
                                @if ($option !== $type) hidden disabled @endif
                            >
                                <legend class="sr-only">{{ $group['presentation']['name'] }} settings</legend>

                                <div class="grid gap-5 sm:grid-cols-2">
                                    <x-provider-fields
                                        :fields="$group['fields']"
                                        :values="$option === $type ? $formConfig : []"
                                        size="lg"
                                        :id-prefix="'source-'.$option"
                                        grid
                                    />
                                </div>
                            </fieldset>
                        @endforeach
                    </div>

                    <div class="flex flex-col gap-4 border-t border-line pt-6 sm:flex-row sm:items-center sm:justify-between">
                        <p class="text-sm text-muted sm:min-w-0 sm:flex-1">Test your settings to preview the latest entry before saving.</p>

                        <div class="flex flex-col-reverse gap-3 sm:shrink-0 sm:flex-row">
                            <x-button
                                type="submit"
                                formaction="{{ route('broadcast-source-previews.store', $project) }}"
                                :hidden="! $current['supportsPreview']"
                                formmethod="POST"
                                variant="ghost"
                                :paw="false"
                                class="whitespace-nowrap"
                                data-source-test
                            >
                                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m7 5 11 7-11 7V5Z" /></svg>
                                Test source
                            </x-button>

                            <x-button class="whitespace-nowrap sm:min-w-44" :paw="false">
                                Save source
                                <x-icons.arrow-right class="size-4" />
                            </x-button>
                        </div>
                    </div>
                </form>
            </section>

            <section
                class="min-w-0 rounded-card border border-line bg-paper-raised lg:sticky lg:top-6"
                aria-labelledby="source-preview-heading"
                data-source-preview
            >
                <div class="flex items-start justify-between gap-3 border-b border-line px-5 py-5 sm:px-6">
                    <div class="min-w-0">
                        <h2 id="source-preview-heading" class="font-display text-xl tracking-tight text-ink">Test preview</h2>
                        <p class="mt-1 text-sm text-muted">Check the latest entry before adding this source.</p>
                    </div>
                    <span class="shrink-0 rounded-md px-2 py-1 font-mono text-[0.625rem] tracking-[0.12em] uppercase {{ $status['class'] }}" data-source-preview-status>{{ $status['label'] }}</span>
                </div>

                <div class="px-5 py-5 sm:px-6" aria-live="polite">
                    @if ($previewError)
                        <p class="rounded-md border border-state-fail/30 bg-state-fail/5 px-3 py-2 text-sm text-state-fail" role="alert">
                            {{ $previewError }}
                        </p>
                    @elseif ($previewEntry)
                        <p class="mb-4 rounded-md border border-state-ok/30 bg-state-ok/5 px-3 py-2 text-sm text-state-ok">
                            Reached the source. This is the latest entry it returned.
                        </p>

                        <article class="flex flex-col gap-2 rounded-lg border border-line p-4">
                            <p class="font-mono text-[0.625rem] tracking-[0.12em] text-accent uppercase">Latest entry</p>

                            @if ($previewEntry->title)
                                <h3 class="text-base font-medium text-ink">{{ $previewEntry->title }}</h3>
                            @endif

                            @if ($previewEntry->published_at || $previewEntry->author)
                                <p class="flex flex-wrap gap-x-2 font-mono text-xs text-muted">
                                    @if ($previewEntry->published_at)
                                        <x-timestamp :value="$previewEntry->published_at" />
                                    @endif
                                    @if ($previewEntry->published_at && $previewEntry->author)
                                        <span aria-hidden="true">&middot;</span>
                                    @endif
                                    @if ($previewEntry->author)
                                        <span>{{ $previewEntry->author }}</span>
                                    @endif
                                </p>
                            @endif

                            @if ($previewEntry->body)
                                <p class="max-h-64 overflow-y-auto text-sm whitespace-pre-wrap text-ink">{{ $previewEntry->body }}</p>
                            @endif

                            @if ($previewEntry->canonical_url)
                                <a class="font-mono text-xs break-all text-accent hover:underline" href="{{ $previewEntry->canonical_url }}" rel="noopener noreferrer" target="_blank">
                                    {{ $previewEntry->canonical_url }} <span aria-hidden="true">&nearr;</span>
                                </a>
                            @endif

                            @if ($previewEntry->external_id)
                                <p class="font-mono text-[0.6875rem] break-all text-muted">External id: {{ $previewEntry->external_id }}</p>
                            @endif
                        </article>
                    @else
                        <div class="flex flex-col items-center gap-3 px-2 py-10 text-center lg:py-16" data-source-preview-empty>
                            <span class="grid size-16 place-items-center rounded-2xl bg-accent/10 text-accent" aria-hidden="true">
                                <x-icons.paw class="size-7" />
                            </span>
                            <h3 class="text-base font-medium text-ink">Your preview will appear here</h3>
                            <p class="max-w-72 text-sm text-muted">
                                Choose a source type, enter its settings, then select <strong class="font-medium text-ink">Test source</strong> to fetch its latest entry.
                            </p>
                            <ul class="mt-1 flex flex-wrap justify-center gap-2 text-xs text-muted">
                                <li class="rounded-md border border-line px-2 py-1">Preview before saving</li>
                                <li class="rounded-md border border-line px-2 py-1">Nothing is saved by a test</li>
                            </ul>
                        </div>
                    @endif
                </div>
            </section>
        </div>

        <p class="text-center text-xs text-muted">Sources collect content. Mapping and delivery are configured in the next steps.</p>
    </div>
</x-layout>
