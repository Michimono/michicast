<x-layout title="New destination">
    @php
        $formName = old('name', '');
        $formConfig = old('config', []);
        $current = $typeGroups[$type];
    @endphp

    <div class="mx-auto flex w-full max-w-3xl min-w-0 flex-col gap-6">
        <section class="flex flex-col items-start gap-3">
            <a
                class="mb-2 inline-flex items-center gap-2 text-sm font-medium text-accent underline-offset-4 hover:underline"
                href="{{ route('broadcasts.show', $project) }}"
            ><span aria-hidden="true">&larr;</span> Back to broadcast</a>

            <span class="max-w-full truncate rounded-md bg-accent/10 px-2.5 py-1 font-mono text-[0.6875rem] tracking-[0.12em] text-accent uppercase">{{ $project->name }} &middot; Destination</span>
            <h1 class="font-display text-4xl tracking-tight text-ink">New destination</h1>
            <p class="max-w-prose text-base text-muted">
                Choose where this broadcast delivers, then fill in its settings and credentials.
            </p>
        </section>

        <section class="relative house-edge min-w-0 rounded-card border bg-paper-raised" aria-labelledby="destination-config-heading">
            <div class="border-b border-line px-5 py-5 sm:px-8">
                <h2 id="destination-config-heading" class="font-display text-xl tracking-tight text-ink">Destination configuration</h2>
                <p class="mt-1 text-sm text-muted">Credentials are stored encrypted and never shown again.</p>
            </div>

            {{--
                Every offered type's fields render in their own disabled fieldset; picking a
                tile enables just that group (app.js, data-destination-form) so only its fields
                post. Without JS, "Show settings" reloads the page for the chosen type.
            --}}
            <form
                class="flex flex-col gap-6 px-5 py-6 sm:px-8"
                method="POST"
                action="{{ route('broadcast-destinations.store', $project) }}"
                data-destination-form
            >
                @csrf

                <fieldset class="m-0 flex min-w-0 flex-col gap-3 border-0 p-0">
                    <legend class="mb-3 flex w-full items-center justify-between gap-3 text-sm font-medium text-ink">
                        <span>Destination type</span>
                        <span class="rounded-md bg-ink/5 px-2 py-1 font-mono text-[0.625rem] tracking-[0.12em] text-muted uppercase">Step 1 of 2</span>
                    </legend>

                    <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                        @foreach ($typeGroups as $option => $group)
                            <label class="group/tile relative block cursor-pointer" data-destination-type-tile>
                                <input
                                    type="radio"
                                    name="type"
                                    value="{{ $option }}"
                                    class="peer sr-only"
                                    data-destination-type-option
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
                            formaction="{{ route('broadcast-destinations.create', $project) }}"
                            formmethod="GET"
                            formnovalidate
                            data-destination-type-reload
                        >Show settings</x-button>
                    </noscript>
                </fieldset>

                <div class="flex flex-col gap-5 border-t border-line pt-6">
                    <div class="flex items-center justify-between gap-3">
                        <h3 class="flex min-w-0 items-center gap-2.5 text-base font-medium text-ink">
                            <span class="grid size-8 shrink-0 place-items-center rounded-md bg-accent/10 font-mono text-xs font-semibold text-accent" aria-hidden="true" data-destination-settings-icon>{{ $current['presentation']['icon'] }}</span>
                            <span data-destination-settings-heading>{{ $current['presentation']['name'] }} settings</span>
                        </h3>
                        <span class="shrink-0 rounded-md bg-ink/5 px-2 py-1 font-mono text-[0.625rem] tracking-[0.12em] text-muted uppercase">Step 2 of 2</span>
                    </div>

                    <x-input
                        name="name"
                        label="Name"
                        size="lg"
                        :value="$formName"
                        placeholder="e.g. News channel"
                        hint="Shown on the broadcast overview."
                        required
                    />

                    @foreach ($typeGroups as $option => $group)
                        <fieldset
                            class="m-0 min-w-0 border-0 p-0"
                            data-destination-type-fields="{{ $option }}"
                            data-destination-type-heading="{{ $group['presentation']['name'] }} settings"
                            data-destination-type-icon="{{ $group['presentation']['icon'] }}"
                            @if ($option !== $type) hidden disabled @endif
                        >
                            <legend class="sr-only">{{ $group['presentation']['name'] }} settings</legend>

                            <div class="grid gap-5 sm:grid-cols-2">
                                <x-provider-fields
                                    :fields="$group['fields']"
                                    :values="$option === $type ? $formConfig : []"
                                    size="lg"
                                    :id-prefix="'destination-'.$option"
                                    grid
                                />
                                <x-credential-fields
                                    :fields="$group['credentialFields']"
                                    size="lg"
                                    :id-prefix="'destination-'.$option"
                                    grid
                                />
                            </div>
                        </fieldset>
                    @endforeach
                </div>

                <div class="flex flex-col gap-4 border-t border-line pt-6 sm:flex-row sm:items-center sm:justify-between">
                    <p class="text-sm text-muted sm:min-w-0 sm:flex-1">You can send a test message once the destination is saved.</p>

                    <x-button class="whitespace-nowrap sm:min-w-44" :paw="false">
                        Save destination
                        <x-icons.arrow-right class="size-4" />
                    </x-button>
                </div>
            </form>
        </section>

        <p class="text-center text-xs text-muted">Destinations deliver content. Mapping decides what each one receives.</p>
    </div>
</x-layout>
