<x-layout :title="$project->name">
    <div class="flex flex-col gap-8">
        {{-- 1. Header: read-only identity + sparse actions --}}
        <x-card eared kicker="Overview">
            <div class="flex flex-col gap-4">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="min-w-0 flex flex-col gap-2">
                        <h1 class="font-display text-2xl tracking-tight">{{ $project->name }}</h1>
                        @if ($project->description)
                            <p class="max-w-2xl text-sm text-ink">{{ $project->description }}</p>
                        @endif
                        <div class="flex flex-wrap items-center gap-2">
                            <x-project-status :status="$project->status" />
                            <x-project-health :health="$health" />
                        </div>
                        <p class="text-sm text-muted">{{ $scheduleSummary }}</p>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        @can('update', $project)
                            <x-button href="{{ route('broadcasts.edit', $project) }}" variant="ghost" :paw="false">Edit</x-button>
                        @endcan

                        <x-button href="{{ route('broadcast-runs.index', $project) }}" variant="ghost" :paw="false">All activity</x-button>

                        @can('markDue', $project)
                            @if ($project->canMarkDue())
                                <form method="POST" action="{{ route('immediate-broadcast-runs.store', $project) }}" data-run-form>
                                    @csrf
                                    <x-button data-run-submit>Run now</x-button>
                                </form>
                            @endif
                        @endcan

                        @can('dryRun', $project)
                            <form method="POST" action="{{ route('broadcast-dry-runs.store', $project) }}" data-run-form>
                                @csrf
                                <x-button variant="ghost" :paw="false" data-run-submit>Dry run</x-button>
                            </form>
                        @endcan

                        @can('changeStatus', $project)
                            @if ($project->canActivate())
                                <form method="POST" action="{{ route('active-broadcasts.store', $project) }}">
                                    @csrf
                                    <x-button>Activate</x-button>
                                </form>
                            @endif

                            @if ($project->canPause())
                                <form method="POST" action="{{ route('paused-broadcasts.store', $project) }}">
                                    @csrf
                                    <x-button variant="ghost" :paw="false">Pause</x-button>
                                </form>
                            @endif

                            @if ($project->canResume())
                                <form method="POST" action="{{ route('paused-broadcasts.destroy', $project) }}">
                                    @csrf
                                    @method('DELETE')
                                    <x-button>Resume</x-button>
                                </form>
                            @endif
                        @endcan
                    </div>
                </div>

                <div hidden data-run-progress class="rounded-md border border-line bg-paper px-4 py-3">
                    <p class="text-sm text-ink">Running…</p>
                    <div class="mt-2 flex items-center gap-2" aria-hidden="true">
                        <x-icons.paw class="size-4 house-text" />
                        <x-icons.paw class="size-4 text-muted" />
                        <x-icons.paw class="size-4 text-muted" />
                    </div>
                </div>

                @if ($autoPausedBroadcasts->isNotEmpty())
                    <div class="rounded-md border border-line bg-paper px-3 py-3 text-sm text-state-fail">
                        <p class="font-medium">
                            {{ $autoPausedBroadcasts->count() === 1 ? 'A broadcast was auto-paused after repeated failures.' : 'Broadcasts were auto-paused after repeated failures.' }}
                        </p>
                        <ul class="mt-2 flex flex-col gap-1">
                            @foreach ($autoPausedBroadcasts as $paused)
                                <li>
                                    <a class="text-accent underline hover:no-underline" href="{{ route('flows.edit', $paused) }}">
                                        {{ $paused->name }}
                                    </a>
                                    @if ($paused->failure_pause_reason)
                                        <span class="text-muted"> — {{ $paused->failure_pause_reason }}</span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>
        </x-card>

        {{-- 2. Pipeline: Source / Mapping / Destination as three vertical cards --}}
        @include('broadcast-mappings.pipeline', [
            'project' => $project,
            'source' => $source,
            'destination' => $destination,
            'mappingEditor' => $mappingEditor ?? ['ready' => false],
        ])

    </div>
</x-layout>