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

                <div class="border-t border-line pt-6">
                    <x-schedule-fields
                        :frequencies="$frequencies"
                        :weekdays="$weekdays"
                        :timezones="$timezones"
                        :schedule="$schedule"
                        :selected-timezone="$selectedTimezone"
                        :timezone-name="$timezoneName"
                        :timezone-source="$timezoneSource"
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
