<x-layout title="New broadcast">
    <div class="grid w-full min-w-0 grid-cols-1 gap-8 lg:grid-cols-[minmax(0,0.8fr)_minmax(0,1.4fr)] lg:gap-12">
        <aside class="flex min-w-0 flex-col gap-4 lg:pt-2">
            <a
                class="inline-flex items-center gap-2 self-start text-sm font-medium text-accent underline-offset-4 hover:underline"
                href="{{ route('broadcasts.index') }}"
            ><span aria-hidden="true">&larr;</span> Back to broadcasts</a>

            <p class="mt-2 font-mono text-[0.6875rem] tracking-[0.12em] text-muted uppercase">New broadcast</p>
            <h1 class="font-display text-4xl tracking-tight text-ink">Create a broadcast</h1>
            <p class="max-w-prose text-sm text-muted">
                Name it after the feed it will run, for example a show or a news beat, and choose how often it should collect from your sources and deliver to your destinations.
            </p>

            <ul class="mt-4 hidden flex-col gap-5 lg:flex">
                @foreach ([
                    ['icon' => 'source', 'title' => 'Automated collection', 'text' => 'Michicast runs your broadcast on the schedule you choose.'],
                    ['icon' => 'destination', 'title' => 'Flexible delivery', 'text' => 'Send to the destinations you connect after creating it.'],
                    ['icon' => 'clock', 'title' => 'Your schedule', 'text' => 'Every few hours, daily or on chosen weekdays, in your timezone.'],
                ] as $feature)
                    <li class="flex items-start gap-4">
                        <span class="grid size-11 shrink-0 place-items-center rounded-full bg-accent/10 text-accent">
                            <x-dynamic-component :component="'icons.'.$feature['icon']" class="size-5" />
                        </span>
                        <span class="flex flex-col gap-0.5">
                            <span class="text-sm font-medium text-ink">{{ $feature['title'] }}</span>
                            <span class="text-sm text-muted">{{ $feature['text'] }}</span>
                        </span>
                    </li>
                @endforeach
            </ul>

            <div class="mt-6 hidden flex-col items-center gap-2 lg:flex">
                <x-brand.mark variant="cat" gradient class="h-20" />
                <p class="text-xs text-muted">Your sources, connected and ready to go.</p>
            </div>
        </aside>

        <x-card class="min-w-0" heading="Broadcast details" kicker="Broadcasts" eared>
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
                    />
                </div>

                <div class="flex flex-col-reverse gap-3 border-t border-line pt-6 sm:flex-row sm:justify-end">
                    <x-button variant="ghost" :href="route('broadcasts.index')">Cancel</x-button>
                    <x-button :paw="false">
                        Create broadcast
                        <x-icons.arrow-right class="size-4" />
                    </x-button>
                </div>
            </form>

            <p class="text-center text-xs text-muted">You can configure sources, mapping and destinations after creation.</p>
        </x-card>
    </div>
</x-layout>
