@php
    $app = config('app.name');

    $summary = $app.' is the visual platform for routing information. Collect what is new from your sources, filter and shape it, and deliver it to the channels your team reads, on your schedule.';

    // One decision for every call to action on the page: the next step this visitor can take.
    $cta = match (true) {
        auth()->check() => ['href' => route('dashboard'), 'label' => 'Open '.$app],
        $registrationOpen => ['href' => route('users.create'), 'label' => 'Get started'],
        default => ['href' => route('session.create'), 'label' => 'Log in'],
    };

    $offersLogIn = auth()->guest() && $registrationOpen;

    $sourceNames = array_values($sources);
    $destinationNames = array_values($destinations);
    $anyDestination = $destinationNames[0] ?? 'a destination';

    $kicker = 'flex items-center gap-3 font-mono text-[0.6875rem] tracking-[0.14em] text-muted uppercase';

    /*
     * Use cases are examples of what the registered providers can do. One that
     * needs a source type this instance does not ship is left out, so the page
     * never describes a pipeline nobody could build here.
     */
    $useCases = array_slice(array_values(array_filter([
        [
            'needs' => 'rss',
            'heading' => 'Security watch',
            'body' => 'Follow the advisory feeds of the software you run, and pass on only the entries that name it.',
            'steps' => [$sources['rss'] ?? '', 'contains: critical', $anyDestination],
        ],
        [
            'needs' => 'web_page',
            'heading' => 'A feed for a page that has none',
            'body' => 'Read a changelog, a notice board or a status page that offers no feed, and treat each new entry as an item.',
            'steps' => [$sources['web_page'] ?? '', 'new entries', $anyDestination],
        ],
        [
            'needs' => 'reddit',
            'heading' => 'Community radar',
            'body' => 'Watch a community for the posts that mention your project and bring them to the people who answer.',
            'steps' => [$sources['reddit'] ?? '', 'title matches a pattern', $anyDestination],
        ],
        [
            'needs' => 'rss',
            'heading' => 'Publish once, reach every channel',
            'body' => 'Connect one feed to several destinations. Each gets every post once, written by its own mapping.',
            'steps' => [$sources['rss'] ?? '', 'one mapping per route', 'several destinations'],
        ],
        [
            'needs' => 'rss',
            'heading' => 'A channel that fills itself',
            'body' => 'Collect several publications into one channel, with the formatting cleaned up and every post cut to the same length.',
            'steps' => ['several feeds', 'clean up, shorten', $anyDestination],
        ],
    ], fn (array $useCase): bool => isset($sources[$useCase['needs']]))), 0, 4);

    // The site navigation, built here so it only links to sections this page renders.
    $sections = array_values(array_filter([
        ['href' => '#how-it-works', 'label' => 'How it works'],
        ['href' => '#capabilities', 'label' => 'Capabilities'],
        $useCases === [] ? null : ['href' => '#use-cases', 'label' => 'Use cases'],
        ['href' => '#teams', 'label' => 'For teams'],
    ]));

    // What each role may do, in the order of authority the policies enforce.
    $roles = [
        ['role' => App\Enums\Role::Viewer, 'may' => 'Sees pipelines, runs and deliveries'],
        ['role' => App\Enums\Role::Editor, 'may' => 'Builds, tests and runs pipelines'],
        ['role' => App\Enums\Role::Admin, 'may' => 'Manages members and settings'],
        ['role' => App\Enums\Role::Owner, 'may' => 'Full control of the organization'],
    ];

    $capabilities = [
        [
            'heading' => 'Test before anything is sent',
            'body' => 'A test run fetches the sources and renders every message. Nothing is delivered until you say so.',
        ],
        [
            'heading' => 'Runs on your schedule',
            'body' => 'Every few hours, daily or weekly, in your own timezone. A source can keep a schedule of its own.',
        ],
        [
            'heading' => 'Once per destination',
            'body' => 'Delivered items are remembered, so running a pipeline again never repeats a message.',
        ],
        [
            'heading' => 'Reasons, not guesses',
            'body' => 'Every run records what it found, what it skipped and why, and what a destination rejected.',
        ],
        [
            'heading' => 'Retry what failed',
            'body' => 'A delivery a destination rejected can be sent again from its history, on its own or all at once.',
        ],
    ];

    // A slice of delivery history. State colour always sits beside the word, as in the app.
    $deliveries = [
        ['time' => '09:02', 'title' => 'Critical: gateway update fixes remote access flaw', 'to' => 'Security team', 'state' => 'Delivered', 'tint' => 'text-state-ok', 'dot' => 'bg-state-ok', 'note' => null],
        ['time' => '09:02', 'title' => 'Critical: gateway update fixes remote access flaw', 'to' => 'Ops channel', 'state' => 'Delivered', 'tint' => 'text-state-ok', 'dot' => 'bg-state-ok', 'note' => null],
        ['time' => '06:00', 'title' => 'Weekly maintenance roundup', 'to' => 'Security team', 'state' => 'Skipped', 'tint' => 'text-state-skip', 'dot' => 'bg-state-skip', 'note' => 'Filter: title does not contain "critical"'],
        ['time' => '03:00', 'title' => 'Scheduled downtime for the status API', 'to' => 'Ops channel', 'state' => 'Failed', 'tint' => 'text-state-fail', 'dot' => 'bg-state-fail', 'note' => 'The destination rejected the message: chat not found'],
    ];
@endphp

<x-site-layout
    :description="$summary"
    :cta="$cta"
    :sections="$sections"
    :log-in="$offersLogIn"
    :documentation-url="$documentationUrl"
    :contact-email="$contactEmail"
>
    {{-- Hero: the headline, then the product itself as the picture. --}}
    <section class="relative isolate" aria-labelledby="hero-heading">
        <div class="canvas-dots absolute inset-x-0 -top-16 -z-10 h-[52rem] mask-b-from-30%" aria-hidden="true"></div>

        <div class="mx-auto w-full max-w-7xl px-5 pt-14 sm:px-8 sm:pt-20 lg:pt-28">
            <div class="grid gap-8 lg:grid-cols-12 lg:items-end lg:gap-12">
                <div class="lg:col-span-8">
                    <p class="{{ $kicker }}">
                        <span class="house-fill h-px w-8" aria-hidden="true"></span>
                        Information pipelines for teams
                    </p>

                    <h1 id="hero-heading" class="mt-6 font-display text-[3.25rem] leading-[0.95] tracking-[-0.02em] text-balance sm:text-7xl lg:text-[5.75rem]">
                        Draw the <em>route</em> your information takes.
                    </h1>
                </div>

                <div class="flex flex-col items-start gap-6 lg:col-span-4 lg:pb-2">
                    <p class="max-w-md text-lg leading-8 text-muted">
                        {{ $app }} collects what is new from your sources, shapes it with filters, transforms and mappings, and delivers it to the channels your team reads. You draw the pipeline. It runs on your schedule.
                    </p>

                    <div class="flex flex-wrap items-center gap-3">
                        <x-button :href="$cta['href']" size="lg">{{ $cta['label'] }}</x-button>

                        <x-button variant="ghost" size="lg" href="#how-it-works">See how it works</x-button>
                    </div>

                    <p class="font-mono text-xs text-muted">Draw it / test it / switch it on</p>
                </div>
            </div>

            <x-pipeline-showcase :sources="$sources" :destinations="$destinations" class="z-10 mt-16 sm:mt-28 lg:mt-32" />
        </div>
    </section>

    {{-- How it works: a raised sheet the canvas above rests on. --}}
    <section id="how-it-works" class="-mt-36 scroll-mt-16 px-2 sm:-mt-44 sm:px-4" aria-labelledby="how-heading">
        <div class="rounded-[2rem] bg-paper-raised pt-52 pb-20 shadow-float sm:rounded-[3rem] sm:pt-72 lg:pb-28">
            <div class="mx-auto grid w-full max-w-7xl gap-12 px-4 sm:px-6 lg:grid-cols-12 lg:gap-10">
                <div class="lg:col-span-4">
                    <div class="lg:sticky lg:top-28">
                        <p class="{{ $kicker }}">
                            <span class="text-ink">01</span>
                            <span class="house-fill h-px w-8" aria-hidden="true"></span>
                            How it works
                        </p>

                        <h2 id="how-heading" class="mt-5 font-display text-5xl leading-none tracking-tight text-balance sm:text-6xl">
                            Three stages, <em>one</em> canvas.
                        </h2>

                        <p class="mt-6 max-w-sm text-base leading-7 text-muted">
                            Every pipeline reads the same way, from where items arrive to where messages go. Start with one source and one destination, and add steps when you need them.
                        </p>
                    </div>
                </div>

                {{-- The stages hang off one line, the way nodes hang off a connection. --}}
                <ol class="flex flex-col gap-16 border-l border-line-strong pl-8 sm:pl-12 lg:col-span-7 lg:col-start-6 lg:gap-20">
                    <li class="relative">
                        <span class="absolute top-0.5 -left-[calc(2rem+0.4375rem)] size-3.5 rounded-full border-2 border-node-source bg-paper-raised sm:-left-[calc(3rem+0.4375rem)]" aria-hidden="true"></span>

                        <p class="font-mono text-[0.6875rem] tracking-[0.14em] text-node-source uppercase">Sources</p>
                        <h3 class="mt-3 font-display text-5xl leading-none tracking-tight">Collect</h3>

                        <p class="mt-4 max-w-xl text-base leading-7 text-muted">
                            Point a source at the place something appears. {{ $app }} checks it on your schedule and picks up only what is new.
                        </p>

                        @if ($sourceNames !== [])
                            <ul class="mt-6 flex flex-wrap gap-2" aria-label="Source types">
                                @foreach ($sourceNames as $name)
                                    <li class="rounded-md border border-line bg-paper px-2.5 py-1 font-mono text-xs text-ink">{{ $name }}</li>
                                @endforeach
                            </ul>
                        @endif
                    </li>

                    <li class="relative">
                        <span class="absolute top-0.5 -left-[calc(2rem+0.4375rem)] size-3.5 rounded-full border-2 border-node-transform bg-paper-raised sm:-left-[calc(3rem+0.4375rem)]" aria-hidden="true"></span>

                        <p class="font-mono text-[0.6875rem] tracking-[0.14em] text-node-transform uppercase">Filters, transforms, mappings</p>
                        <h3 class="mt-3 font-display text-5xl leading-none tracking-tight">Shape</h3>

                        <p class="mt-4 max-w-xl text-base leading-7 text-muted">
                            Decide what gets through and how it reads when it arrives. Steps connect in the order you draw them.
                        </p>

                        <dl class="mt-6 grid gap-x-6 gap-y-3 text-sm sm:grid-cols-[6.5rem_1fr]">
                            <dt class="font-mono text-xs leading-6 text-ink">Filter</dt>
                            <dd class="leading-6 text-muted">Keep items by what the title or body contains, a title pattern, the author, or how recently they were published.</dd>

                            <dt class="font-mono text-xs leading-6 text-ink">Transform</dt>
                            <dd class="leading-6 text-muted">Clean up formatting, shorten long text, add an opening or a closing line, or replace by pattern.</dd>

                            <dt class="font-mono text-xs leading-6 text-ink">Mapping</dt>
                            <dd class="leading-6 text-muted">Choose which fields of the item fill which parts of the message.</dd>
                        </dl>
                    </li>

                    <li class="relative">
                        <span class="absolute top-0.5 -left-[calc(2rem+0.4375rem)] size-3.5 rounded-full border-2 border-node-destination bg-paper-raised sm:-left-[calc(3rem+0.4375rem)]" aria-hidden="true"></span>

                        <p class="font-mono text-[0.6875rem] tracking-[0.14em] text-node-destination uppercase">Destinations</p>
                        <h3 class="mt-3 font-display text-5xl leading-none tracking-tight">Deliver</h3>

                        <p class="mt-4 max-w-xl text-base leading-7 text-muted">
                            Send each item to one destination or to several. One source can feed many, and many sources can share one.
                        </p>

                        @if ($destinationNames !== [])
                            <ul class="mt-6 flex flex-wrap gap-2" aria-label="Destination types">
                                @foreach ($destinationNames as $name)
                                    <li class="rounded-md border border-line bg-paper px-2.5 py-1 font-mono text-xs text-ink">{{ $name }}</li>
                                @endforeach
                            </ul>
                        @endif
                    </li>
                </ol>
            </div>
        </div>
    </section>

    {{-- Capabilities: the claims on one side, the record that backs them on the other. --}}
    <section id="capabilities" class="mx-auto w-full max-w-7xl scroll-mt-16 px-5 pt-24 sm:px-8 lg:pt-36" aria-labelledby="capabilities-heading">
        <div class="grid gap-16 lg:grid-cols-12 lg:gap-10">
            <div class="lg:col-span-5">
                <p class="{{ $kicker }}">
                    <span class="text-ink">02</span>
                    <span class="house-fill h-px w-8" aria-hidden="true"></span>
                    Capabilities
                </p>

                <h2 id="capabilities-heading" class="mt-5 font-display text-5xl leading-none tracking-tight text-balance sm:text-6xl">
                    Know what was sent, and <em>why</em> the rest was not.
                </h2>

                <dl class="mt-12 grid gap-x-10 gap-y-8 sm:grid-cols-2 lg:grid-cols-1">
                    @foreach ($capabilities as $capability)
                        <div class="flex gap-4">
                            <span class="mt-0.5 font-mono text-xs text-line-strong" aria-hidden="true">{{ sprintf('%02d', $loop->iteration) }}</span>

                            <div>
                                <dt class="text-base font-semibold text-ink">{{ $capability['heading'] }}</dt>
                                <dd class="mt-1 max-w-sm text-sm leading-6 text-muted">{{ $capability['body'] }}</dd>
                            </div>
                        </div>
                    @endforeach
                </dl>
            </div>

            <div class="lg:col-span-7 lg:pt-24" role="img" aria-label="An example of delivery history: two messages delivered, one skipped by a filter, and one rejected by the destination, each with its reason.">
                <div class="relative lg:-mr-6" aria-hidden="true">
                    <div class="overflow-hidden rounded-2xl border border-line bg-paper-raised shadow-canvas">
                        <div class="px-5 pt-5 pb-4 sm:px-7 sm:pt-6">
                            <p class="font-mono text-[0.625rem] tracking-[0.14em] text-muted uppercase">Security watch</p>
                            <p class="mt-1 font-display text-2xl tracking-tight">Delivery history</p>
                        </div>

                        <ul class="divide-y divide-line border-t border-line">
                            @foreach ($deliveries as $delivery)
                                <li class="grid grid-cols-[2.75rem_1fr] gap-x-3 px-5 py-3.5 sm:grid-cols-[2.75rem_1fr_auto] sm:gap-x-4 sm:px-7">
                                    <span class="font-mono text-xs leading-6 text-muted">{{ $delivery['time'] }}</span>

                                    <div class="min-w-0">
                                        <p class="truncate text-sm leading-6 font-medium text-ink">{{ $delivery['title'] }}</p>
                                        <p class="font-mono text-xs leading-5 text-muted">{{ $delivery['to'] }}</p>

                                        @if ($delivery['note'])
                                            <p class="mt-1 text-xs leading-5 text-muted">{{ $delivery['note'] }}</p>
                                        @endif
                                    </div>

                                    <span class="col-start-2 inline-flex items-center gap-1.5 font-mono text-[0.6875rem] leading-6 tracking-wide uppercase sm:col-start-3 sm:self-start {{ $delivery['tint'] }}">
                                        <span class="size-1.5 rounded-full {{ $delivery['dot'] }}"></span>
                                        {{ $delivery['state'] }}
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    </div>

                    <div class="relative -mt-4 mr-4 ml-auto w-60 rounded-xl border border-line bg-paper-raised p-4 shadow-float sm:absolute sm:-top-12 sm:right-8 sm:m-0 lg:-right-4">
                        <p class="font-mono text-[0.625rem] tracking-[0.14em] text-muted uppercase">Schedule</p>
                        <p class="mt-1.5 font-display text-2xl leading-tight tracking-tight">Every 3 hours</p>
                        <p class="mt-1 font-mono text-xs text-muted">Europe/Madrid / next run 12:00</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    @if ($useCases !== [])
        {{-- Use cases: each one is a pipeline, so each is written as one. --}}
        <section id="use-cases" class="mx-auto w-full max-w-7xl scroll-mt-16 px-5 pt-28 sm:px-8 lg:pt-44" aria-labelledby="use-cases-heading">
            <div class="grid gap-14 lg:grid-cols-12 lg:gap-10">
                <div class="lg:col-span-4">
                    <p class="{{ $kicker }}">
                        <span class="text-ink">03</span>
                        <span class="house-fill h-px w-8" aria-hidden="true"></span>
                        Use cases
                    </p>

                    <h2 id="use-cases-heading" class="mt-5 font-display text-5xl leading-none tracking-tight text-balance sm:text-6xl">
                        A few pipelines to <em>start</em> from.
                    </h2>

                    <p class="mt-6 max-w-sm text-base leading-7 text-muted">
                        These are examples, not limits. Anything a source can read can go anywhere a destination can reach.
                    </p>
                </div>

                <ul class="grid gap-x-12 gap-y-14 sm:grid-cols-2 lg:col-span-8">
                    @foreach ($useCases as $useCase)
                        <li class="sm:even:mt-20">
                            {{-- A pipeline in miniature: source, step, destination. --}}
                            <div class="flex items-center" aria-hidden="true">
                                <span class="size-2.5 rounded-full border-2 border-node-source"></span>
                                <span class="h-px w-10 bg-line-strong"></span>
                                <span class="size-2.5 rounded-full border-2 border-node-transform"></span>
                                <span class="h-px w-10 bg-line-strong"></span>
                                <span class="size-2.5 rounded-full border-2 border-node-destination"></span>
                            </div>

                            <h3 class="mt-5 font-display text-3xl leading-tight tracking-tight">{{ $useCase['heading'] }}</h3>
                            <p class="mt-3 max-w-sm text-base leading-7 text-muted">{{ $useCase['body'] }}</p>

                            <p class="mt-5 flex flex-wrap items-center gap-x-2 gap-y-1 font-mono text-xs">
                                <span class="text-node-source">{{ $useCase['steps'][0] }}</span>
                                <x-icons.arrow-right class="size-3.5 text-line-strong" />
                                <span class="text-node-transform">{{ $useCase['steps'][1] }}</span>
                                <x-icons.arrow-right class="size-3.5 text-line-strong" />
                                <span class="text-node-destination">{{ $useCase['steps'][2] }}</span>
                            </p>
                        </li>
                    @endforeach
                </ul>
            </div>
        </section>
    @endif

    {{-- For teams: the one dark surface on the page, where the gradient mark can clear its background. --}}
    <section id="teams" class="scroll-mt-16 px-2 pt-28 sm:px-4 lg:pt-44" aria-labelledby="teams-heading">
        <div class="relative overflow-hidden rounded-[2rem] bg-ink text-ink-contrast sm:rounded-[3rem]">
            <div class="mx-auto grid w-full max-w-7xl gap-14 px-6 py-16 sm:px-10 lg:grid-cols-12 lg:gap-10 lg:py-28">
                <div class="flex flex-col items-start lg:col-span-7">
                    <p class="flex items-center gap-3 font-mono text-[0.6875rem] tracking-[0.14em] text-ink-muted uppercase">
                        <span class="text-ink-contrast">04</span>
                        <span class="house-fill h-px w-8" aria-hidden="true"></span>
                        For teams
                    </p>

                    <h2 id="teams-heading" class="mt-5 font-display text-5xl leading-none tracking-tight text-balance sm:text-6xl lg:text-7xl">
                        A seat for everyone, and the <em>right</em> one.
                    </h2>

                    <p class="mt-6 max-w-xl text-lg leading-8 text-ink-contrast/80">
                        Each organization keeps its own pipelines, sources and destinations. Invite the people who need to see what was delivered, and decide who may change what is sent.
                    </p>

                    <ul class="mt-8 flex flex-col gap-2.5 text-base text-ink-contrast/80">
                        @foreach (['Invitations by email, with a role from the start', 'Two-factor sign-in with recovery codes', 'Destination credentials encrypted at rest'] as $assurance)
                            <li class="flex items-baseline gap-3">
                                <span class="h-px w-4 shrink-0 -translate-y-1 bg-ink-muted" aria-hidden="true"></span>
                                {{ $assurance }}
                            </li>
                        @endforeach
                    </ul>

                    <div class="mt-10 flex flex-wrap items-center gap-3">
                        <a
                            class="inline-flex h-12 items-center gap-2 rounded-lg bg-paper-raised px-5 text-base font-medium text-ink transition-colors hover:bg-ink-contrast focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ink-contrast"
                            href="{{ $cta['href'] }}"
                        >
                            {{ $cta['label'] }}
                            <x-icons.arrow-right class="size-4" />
                        </a>

                        @if ($contactEmail)
                            <a
                                class="inline-flex h-12 items-center gap-2 rounded-lg border border-ink-muted px-5 text-base font-medium text-ink-contrast transition-colors hover:border-ink-contrast focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ink-contrast"
                                href="mailto:{{ $contactEmail }}"
                            >
                                Talk to our team
                            </a>
                        @endif
                    </div>
                </div>

                <div class="flex flex-col gap-10 lg:col-span-4 lg:col-start-9 lg:items-end">
                    <x-brand.mark variant="cat" gradient class="h-36 lg:h-44" />

                    <dl class="grid w-full grid-cols-[4.5rem_1fr] gap-x-4 gap-y-3 font-mono text-xs">
                        @foreach ($roles as $entry)
                            <dt class="text-ink-muted">{{ $entry['role']->label() }}</dt>
                            <dd>{{ $entry['may'] }}</dd>
                        @endforeach
                    </dl>
                </div>
            </div>
        </div>
    </section>

    {{-- The way in: one sentence and one action. --}}
    <section class="relative isolate" aria-labelledby="start-heading">
        <div class="canvas-dots absolute inset-0 -z-10 mask-t-from-40% mask-b-from-60%" aria-hidden="true"></div>

        <div class="mx-auto grid w-full max-w-7xl gap-8 px-5 pt-28 pb-6 sm:px-8 lg:grid-cols-12 lg:items-end lg:gap-12 lg:pt-44">
            <h2 id="start-heading" class="font-display text-5xl leading-[0.95] tracking-tight text-balance sm:text-7xl lg:col-span-8">
                Start with one source and one <em>destination</em>.
            </h2>

            <div class="flex flex-col items-start gap-6 lg:col-span-4 lg:pb-2">
                <p class="max-w-sm text-lg leading-8 text-muted">
                    Add filters, transforms and more routes when the first one works.
                </p>

                <div class="flex flex-wrap items-center gap-x-5 gap-y-3">
                    <x-button :href="$cta['href']" size="lg">{{ $cta['label'] }}</x-button>

                    @if ($offersLogIn)
                        <a class="rounded-sm text-sm text-ink underline underline-offset-4 hover:no-underline focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-accent" href="{{ route('session.create') }}">
                            I already have an account
                        </a>
                    @endif
                </div>
            </div>
        </div>
    </section>
</x-site-layout>
