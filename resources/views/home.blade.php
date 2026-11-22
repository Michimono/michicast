@php
    $app = config('app.name');

    $summary = $app.' collects new items from the sources you choose, filters and reshapes them, and delivers them to your channels on a schedule.';

    // One decision for both calls to action: where this visitor can go next.
    $primary = match (true) {
        auth()->check() => ['href' => route('dashboard'), 'label' => 'Go to your dashboard'],
        $registrationOpen => ['href' => route('users.create'), 'label' => 'Create an account'],
        default => ['href' => route('session.create'), 'label' => 'Log in'],
    };

    $offersLogin = auth()->guest() && $registrationOpen;

    // Each stage wears its node colour beside its label, as on the pipeline canvas.
    $stages = [
        [
            'icon' => 'source',
            'tile' => 'bg-node-source/10 text-node-source',
            'tint' => 'text-node-source',
            'label' => 'Sources',
            'heading' => 'Collect',
            'body' => 'Point a source at a feed, a page or a profile. New items are picked up on your schedule.',
            'items' => $sources,
        ],
        [
            'icon' => 'mapping',
            'tile' => 'bg-node-transform/10 text-node-transform',
            'tint' => 'text-node-transform',
            'label' => 'Steps',
            'heading' => 'Shape',
            'body' => 'Keep only the items that matter, then decide how each one reads when it arrives.',
            'items' => ['Filters', 'Mappings', 'Transforms'],
        ],
        [
            'icon' => 'destination',
            'tile' => 'bg-node-destination/10 text-node-destination',
            'tint' => 'text-node-destination',
            'label' => 'Destinations',
            'heading' => 'Deliver',
            'body' => 'Send each item to one channel or to several. Every item arrives once.',
            'items' => $destinations,
        ],
    ];

    $features = [
        [
            'icon' => 'overview',
            'heading' => 'Draw the pipeline',
            'body' => 'Connect sources, filters, mappings and destinations on a canvas. One source can feed several destinations, and several sources can share one.',
        ],
        [
            'icon' => 'broadcast',
            'heading' => 'Test before you send',
            'body' => 'A dry run shows what each step lets through and what would be published. Nothing is sent.',
        ],
        [
            'icon' => 'clock',
            'heading' => 'Runs on your schedule',
            'body' => 'Every few hours, daily or weekly, in your own timezone. Each source can keep a schedule of its own.',
        ],
        [
            'icon' => 'stack',
            'heading' => 'Never posts twice',
            'body' => 'Delivered items are remembered, so running a pipeline again does not repeat them.',
        ],
        [
            'icon' => 'activity',
            'heading' => 'See what happened',
            'body' => 'Each run records what was found, what was skipped and why, what was delivered and what the destination rejected.',
        ],
        [
            'icon' => 'users',
            'heading' => 'Made for teams',
            'body' => 'Organizations keep their pipelines separate, with owner, admin, editor and viewer roles.',
        ],
    ];
@endphp

<x-layout :description="$summary">
    <section class="flex items-center justify-between gap-10 py-4 sm:py-8" aria-labelledby="landing-heading">
        <div class="flex flex-col items-start gap-5">
            <p class="font-mono text-[0.6875rem] tracking-[0.12em] text-muted uppercase">
                A {{ config('michicast.brand') }} product
            </p>

            <h1 id="landing-heading" class="max-w-2xl font-display text-4xl leading-[1.1] tracking-tight sm:text-5xl">
                Move information from where it appears to where it is read.
            </h1>

            <span class="house-fill h-0.5 w-16 rounded-full" aria-hidden="true"></span>

            <p class="max-w-2xl text-base text-muted sm:text-lg">
                {{ $summary }} You draw the pipeline, test it, and see exactly what was sent.
            </p>

            <div class="flex flex-wrap items-center gap-3">
                <x-button :href="$primary['href']">{{ $primary['label'] }}</x-button>

                @if ($offersLogin)
                    <x-button variant="ghost" :href="route('session.create')">Log in</x-button>
                @endif
            </div>
        </div>

        <x-brand.mark variant="cat" class="hidden h-44 shrink-0 md:block" />
    </section>

    <x-whisker-divider />

    <section class="flex flex-col gap-6 py-4" aria-labelledby="how-it-works-heading">
        <div class="flex flex-col gap-1.5">
            <h2 id="how-it-works-heading" class="font-display text-3xl tracking-tight">How it works</h2>

            <p class="max-w-2xl text-sm text-muted sm:text-base">
                Every pipeline has the same three stages. Start with one source and one destination, and add steps as you need them.
            </p>
        </div>

        <ol class="grid gap-4 md:grid-cols-3 md:gap-8">
            @foreach ($stages as $stage)
                <li class="relative flex flex-col gap-3 rounded-card border border-line bg-paper-raised p-5">
                    <div class="flex items-center gap-3">
                        <span class="flex size-9 shrink-0 items-center justify-center rounded-lg {{ $stage['tile'] }}">
                            <x-dynamic-component :component="'icons.'.$stage['icon']" />
                        </span>

                        <span class="font-mono text-[0.6875rem] tracking-[0.12em] uppercase {{ $stage['tint'] }}">
                            {{ $stage['label'] }}
                        </span>
                    </div>

                    <h3 class="font-display text-2xl tracking-tight">{{ $stage['heading'] }}</h3>

                    <p class="text-sm text-muted">{{ $stage['body'] }}</p>

                    @if ($stage['items'] !== [])
                        <ul class="mt-auto flex flex-wrap gap-1.5 pt-1" aria-label="{{ $stage['label'] }}">
                            @foreach ($stage['items'] as $item)
                                <li class="rounded-md border border-line bg-paper px-2 py-0.5 font-mono text-xs text-ink">{{ $item }}</li>
                            @endforeach
                        </ul>
                    @endif

                    @unless ($loop->last)
                        <x-icons.arrow-right class="absolute top-1/2 -right-4 hidden translate-x-1/2 -translate-y-1/2 text-line-strong md:block" />
                    @endunless
                </li>
            @endforeach
        </ol>
    </section>

    <section class="flex flex-col gap-6 py-4" aria-labelledby="features-heading">
        <h2 id="features-heading" class="font-display text-3xl tracking-tight">What you get</h2>

        <ul class="grid gap-x-8 gap-y-6 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($features as $feature)
                <li class="flex items-start gap-3">
                    <x-dynamic-component :component="'icons.'.$feature['icon']" class="mt-0.5 shrink-0 text-accent" />

                    <div class="flex flex-col gap-1">
                        <h3 class="text-sm font-semibold text-ink">{{ $feature['heading'] }}</h3>

                        <p class="text-sm text-muted">{{ $feature['body'] }}</p>
                    </div>
                </li>
            @endforeach
        </ul>
    </section>

    <x-whisker-divider />

    <x-card eared kicker="Open source" heading="Yours to read, run and extend" class="mt-2">
        <p class="max-w-prose text-sm text-muted">
            {{ $app }} is open source under the MIT license. Run your own instance, check how your content is handled, or add a source or destination of your own.
        </p>

        <div class="flex flex-wrap items-center gap-3 pt-1">
            <x-button :href="$primary['href']">{{ $primary['label'] }}</x-button>

            @if ($repositoryUrl)
                <x-button variant="ghost" :href="$repositoryUrl">
                    <x-icons.github />
                    View the source code
                </x-button>
            @endif
        </div>
    </x-card>
</x-layout>
