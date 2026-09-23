<x-layout :title="$project->name">
    <x-card :heading="$project->name" kicker="Projects" eared>
        <div class="flex flex-wrap items-center gap-3">
            <p class="font-mono text-xs text-muted">{{ $project->slug }}</p>
            <x-project-status :status="$project->status" />
        </div>

        @if ($project->description)
            <p class="text-sm text-ink">{{ $project->description }}</p>
        @endif

        @if ($autoPausedBroadcasts->isNotEmpty())
            <div class="rounded-md border border-line bg-paper px-3 py-3 text-sm text-state-fail">
                <p class="font-medium">
                    {{ $autoPausedBroadcasts->count() === 1 ? 'A broadcast was auto-paused after repeated failures.' : 'Broadcasts were auto-paused after repeated failures.' }}
                </p>
                <ul class="mt-2 flex flex-col gap-1">
                    @foreach ($autoPausedBroadcasts as $broadcast)
                        <li>
                            <a class="text-accent underline hover:no-underline" href="{{ route('broadcasts.edit', $broadcast) }}">
                                {{ $broadcast->name }}
                            </a>
                            @if ($broadcast->failure_pause_reason)
                                <span class="text-muted"> — {{ $broadcast->failure_pause_reason }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if ($isEmpty)
            <x-empty-state
                heading="Nothing to chase yet"
                message="Add a source so this project has somewhere to read from. Destinations and broadcasts come next."
            >
                @can('create', [App\Models\Source::class, $project])
                    <x-button href="{{ route('project-sources.create', $project) }}">Add a source</x-button>
                @endcan
            </x-empty-state>
        @else
            <dl class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <div>
                    <dt class="text-xs font-medium tracking-wide text-muted uppercase">Status</dt>
                    <dd class="text-sm text-ink">{{ $project->status->label() }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium tracking-wide text-muted uppercase">Next run</dt>
                    <dd class="font-mono text-sm text-ink">
                        @if ($project->next_run_at)
                            {{ $project->next_run_at->timezone($project->effectiveTimezone())->format('l, F j, Y \a\t g:i A T') }}
                            <span class="text-muted">({{ $project->effectiveTimezone() }})</span>
                        @else
                            <span class="font-sans text-muted">No schedule yet</span>
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-xs font-medium tracking-wide text-muted uppercase">Last run</dt>
                    <dd class="text-sm text-ink">
                        @if ($lastRun)
                            {{ $lastRun->status->label() }}
                            @if ($lastRun->is_dry)
                                <span class="font-mono text-xs text-muted">(dry run)</span>
                            @endif
                            ·
                            <span class="font-mono text-xs text-muted">
                                {{ ($lastRun->finished_at ?? $lastRun->started_at)->diffForHumans() }}
                            </span>
                        @else
                            <span class="text-muted">Never run</span>
                        @endif
                    </dd>
                </div>
            </dl>

            <div class="grid gap-3 sm:grid-cols-3">
                @can('viewAny', [App\Models\Source::class, $project])
                    <a
                        class="rounded-md border border-line bg-paper px-4 py-3 hover:border-line-strong"
                        href="{{ route('project-sources.index', $project) }}"
                    >
                        <p class="text-xs font-medium tracking-wide text-muted uppercase">Sources</p>
                        <p class="font-mono text-2xl text-ink">{{ $project->sources_count }}</p>
                    </a>
                @endcan

                @can('viewAny', [App\Models\Destination::class, $project])
                    <a
                        class="rounded-md border border-line bg-paper px-4 py-3 hover:border-line-strong"
                        href="{{ route('project-destinations.index', $project) }}"
                    >
                        <p class="text-xs font-medium tracking-wide text-muted uppercase">Destinations</p>
                        <p class="font-mono text-2xl text-ink">{{ $project->destinations_count }}</p>
                    </a>
                @endcan

                @can('viewAny', [App\Models\Broadcast::class, $project])
                    <a
                        class="rounded-md border border-line bg-paper px-4 py-3 hover:border-line-strong"
                        href="{{ route('project-broadcasts.index', $project) }}"
                    >
                        <p class="text-xs font-medium tracking-wide text-muted uppercase">Broadcasts</p>
                        <p class="font-mono text-2xl text-ink">{{ $project->broadcasts_count }}</p>
                    </a>
                @endcan
            </div>
        @endif

        <div class="flex flex-wrap items-center gap-2">
            @can('update', $project)
                <x-button href="{{ route('projects.edit', $project) }}" variant="ghost" :paw="false">Edit project</x-button>
                <x-button href="{{ route('project-schedules.edit', $project) }}" variant="ghost" :paw="false">Schedule</x-button>
            @endcan

            @can('markDue', $project)
                @if ($project->canMarkDue())
                    <form method="POST" action="{{ route('due-projects.store', $project) }}">
                        @csrf
                        <x-button>Run now</x-button>
                    </form>
                @endif
            @endcan

            @can('dryRun', $project)
                <form method="POST" action="{{ route('broadcast-dry-runs.store', $project) }}">
                    @csrf
                    <x-button variant="ghost" :paw="false">Dry run</x-button>
                </form>
            @endcan

            @can('changeStatus', $project)
                @if ($project->canActivate())
                    <form method="POST" action="{{ route('active-projects.store', $project) }}">
                        @csrf
                        <x-button>Activate</x-button>
                    </form>
                @endif

                @if ($project->canPause())
                    <form method="POST" action="{{ route('paused-projects.store', $project) }}">
                        @csrf
                        <x-button variant="ghost" :paw="false">Pause</x-button>
                    </form>
                @endif

                @if ($project->canResume())
                    <form method="POST" action="{{ route('paused-projects.destroy', $project) }}">
                        @csrf
                        @method('DELETE')
                        <x-button>Resume</x-button>
                    </form>
                @endif

                @if ($project->canArchive())
                    <form method="POST" action="{{ route('archived-projects.store', $project) }}">
                        @csrf
                        <x-button variant="ghost" :paw="false">Archive</x-button>
                    </form>
                @endif

                @if ($project->canRestore())
                    <form method="POST" action="{{ route('archived-projects.destroy', $project) }}">
                        @csrf
                        @method('DELETE')
                        <x-button>Restore</x-button>
                    </form>
                @endif
            @endcan
        </div>
    </x-card>

    @unless ($isEmpty)
        <x-card heading="Recent runs" :kicker="$project->name">
            @if ($recentRuns->isEmpty())
                <p class="text-sm text-muted">This project has never run.</p>
            @else
                <ul class="flex flex-col divide-y divide-line border-t border-line">
                    @foreach ($recentRuns as $run)
                        <li class="flex flex-wrap items-center justify-between gap-4 py-4">
                            <div class="flex flex-col gap-1">
                                <a class="text-sm font-medium text-ink hover:text-accent" href="{{ route('project-runs.show', [$project, $run]) }}">
                                    <x-timestamp :value="$run->started_at" />
                                </a>
                                <p class="font-mono text-xs text-muted">
                                    @if ($run->is_dry)
                                        Dry run ·
                                    @endif
                                    {{ $run->status->label() }}
                                    · found {{ $run->items_found }}
                                    · new {{ $run->items_new }}
                                    · skipped {{ $run->items_skipped }}
                                    · {{ $run->is_dry ? 'would deliver' : 'delivered' }} {{ $run->items_delivered }}
                                    · failed {{ $run->items_failed }}
                                </p>
                            </div>

                            <x-button href="{{ route('project-runs.show', [$project, $run]) }}" variant="ghost" :paw="false">Open</x-button>
                        </li>
                    @endforeach
                </ul>
            @endif

            <p>
                <x-button href="{{ route('project-runs.index', $project) }}" variant="ghost" :paw="false">All runs</x-button>
            </p>
        </x-card>
    @endunless
</x-layout>