<x-layout title="New broadcast">
    <div class="mx-auto flex w-full max-w-3xl min-w-0 flex-col gap-6">
        <section class="flex flex-col items-start gap-3">
            <a
                class="mb-2 inline-flex items-center gap-2 text-sm font-medium text-accent underline-offset-4 hover:underline"
                href="{{ route('broadcasts.index') }}"
            ><span aria-hidden="true">&larr;</span> Back to broadcasts</a>

            <span class="rounded-md bg-accent/10 px-2.5 py-1 font-mono text-[0.6875rem] tracking-[0.12em] text-accent uppercase">New broadcast</span>
            <h1 class="font-display text-4xl tracking-tight text-ink">Create a broadcast</h1>
            <p class="max-w-prose text-base text-muted">
                Set up a schedule to collect content from your sources and deliver it to your destinations.
            </p>
        </section>

        <section
            class="relative house-edge min-w-0 rounded-card border p-5 sm:p-8"
            aria-label="Broadcast details and schedule"
        >
            <form class="flex flex-col gap-6" method="POST" action="{{ route('broadcasts.store') }}">
                @csrf

                <x-input
                    name="name"
                    label="Broadcast name"
                    placeholder="e.g. Daily Pokémon News"
                    hint="A clear name helps you find this broadcast later."
                    autofocus
                    required
                />

                <x-textarea
                    name="description"
                    label="Description (optional)"
                    placeholder="e.g. Collects the latest Pokémon news and posts it to our channel."
                    hint="Shown to everyone in the organization."
                />

                @php
                    $selectedKind = old('kind', App\Enums\ProjectKind::Simple->value);
                @endphp

                <fieldset class="m-0 flex min-w-0 flex-col gap-3 border-0 p-0">
                    <legend class="mb-3 text-sm font-medium text-ink">Broadcast type</legend>

                    <div class="grid gap-2 sm:grid-cols-2">
                        @foreach (App\Enums\ProjectKind::cases() as $kind)
                            <label class="group/tile relative block cursor-pointer">
                                <input
                                    type="radio"
                                    name="kind"
                                    value="{{ $kind->value }}"
                                    class="peer sr-only"
                                    @checked($kind->value === $selectedKind)
                                >
                                <span class="flex h-full flex-col gap-1.5 rounded-lg border border-line bg-paper-raised px-4 py-3 transition-colors hover:border-line-strong peer-checked:border-accent peer-checked:bg-accent/5 peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-accent">
                                    <span class="flex items-center gap-2">
                                        <span class="grid size-8 place-items-center rounded-md bg-ink/5 text-muted group-has-checked/tile:bg-accent/10 group-has-checked/tile:text-accent" aria-hidden="true">
                                            @if ($kind === App\Enums\ProjectKind::Visual)
                                                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="6" height="6" rx="1" /><rect x="15" y="4" width="6" height="6" rx="1" /><rect x="9" y="14" width="6" height="6" rx="1" /><path d="M9 7h6" /><path d="M6 10v4h6" /><path d="M18 10v4h-6" /></svg>
                                            @else
                                                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="9" width="5" height="6" rx="1" /><rect x="16" y="9" width="5" height="6" rx="1" /><path d="M8 12h8" /><path d="m13 9 3 3-3 3" /></svg>
                                            @endif
                                        </span>
                                        <span class="text-sm font-medium text-ink">{{ $kind->label() }}</span>
                                    </span>
                                    <span class="text-xs text-muted">{{ $kind->description() }}</span>
                                </span>
                            </label>
                        @endforeach
                    </div>

                    @error('kind')
                        <p class="text-sm text-state-fail">{{ $message }}</p>
                    @enderror
                </fieldset>

                <div class="border-t border-line pt-6">
                    <x-schedule-fields
                        :frequencies="$frequencies"
                        :weekdays="$weekdays"
                        :timezones="$timezones"
                        :schedule="$schedule"
                        :selected-timezone="$selectedTimezone"
                        separate-timezone
                    />
                </div>

                <div class="flex flex-col-reverse gap-3 pt-2 sm:flex-row sm:justify-end">
                    <x-button variant="ghost" :href="route('broadcasts.index')">Cancel</x-button>
                    <x-button class="sm:min-w-48" :paw="false">
                        Create broadcast
                        <x-icons.arrow-right class="size-4" />
                    </x-button>
                </div>
            </form>
        </section>

        <p class="text-center text-xs text-muted">You can configure sources, mapping and destinations after creation.</p>
    </div>
</x-layout>
