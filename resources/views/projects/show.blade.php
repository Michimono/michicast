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
                            <x-button type="button" variant="ghost" :paw="false" data-open-dialog="schedule-drawer">
                                {{ $project->schedule ? 'Edit schedule' : 'Set schedule' }}
                            </x-button>
                        @endcan

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

                            @if ($project->canArchive())
                                <form
                                    method="POST"
                                    action="{{ route('archived-broadcasts.store', $project) }}"
                                    onsubmit="return confirm('Archive this broadcast? Historical activity is kept.')"
                                >
                                    @csrf
                                    <x-button variant="ghost" :paw="false">Archive</x-button>
                                </form>
                            @endif

                            @if ($project->canRestore())
                                <form method="POST" action="{{ route('archived-broadcasts.destroy', $project) }}">
                                    @csrf
                                    @method('DELETE')
                                    <x-button>Restore</x-button>
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

        {{-- 2. Pipeline centrepiece: Source → Destination --}}
        <x-card heading="Pipeline" kicker="Flow">
            <x-slot:actions>
                @if (! $source)
                    @can('create', [App\Models\Source::class, $project])
                        <x-button href="{{ route('broadcast-sources.create', $project) }}" variant="ghost" :paw="false">Add a source</x-button>
                    @endcan
                @endif
                @if (! $destination)
                    @can('create', [App\Models\Destination::class, $project])
                        <x-button href="{{ route('broadcast-destinations.create', $project) }}" variant="ghost" :paw="false">Add destination</x-button>
                    @endcan
                @endif
            </x-slot:actions>

            <p class="mb-4 text-sm text-muted">Source to destination. Transforms can join this path later.</p>

            <div class="flex flex-col items-stretch gap-4 sm:flex-row sm:items-center">
                <div class="min-w-0 flex-1 rounded-card border border-line bg-paper p-4">
                    <p class="font-mono text-[0.6875rem] tracking-wide text-muted uppercase">Source</p>
                    @if ($source)
                        <a class="mt-1 block truncate font-display text-xl tracking-tight text-ink hover:text-accent" href="{{ route('sources.edit', $source) }}">
                            {{ $source->name }}
                        </a>
                        <div class="mt-3">
                            <x-button href="{{ route('sources.edit', $source) }}" variant="ghost" :paw="false">Manage</x-button>
                        </div>
                    @else
                        <p class="mt-2 text-sm text-muted">Nothing feeding this broadcast yet.</p>
                    @endif
                </div>

                <div class="flex justify-center text-muted" aria-hidden="true">
                    <x-icons.arrow-right class="size-6 rotate-90 sm:rotate-0" />
                </div>

                <div class="min-w-0 flex-1 rounded-card border border-line bg-paper p-4">
                    <p class="font-mono text-[0.6875rem] tracking-wide text-muted uppercase">Destination</p>
                    @if ($destination)
                        <a class="mt-1 block truncate font-display text-xl tracking-tight text-ink hover:text-accent" href="{{ route('destinations.edit', $destination) }}">
                            {{ $destination->name }}
                        </a>
                        <div class="mt-3">
                            <x-button href="{{ route('destinations.edit', $destination) }}" variant="ghost" :paw="false">Manage</x-button>
                        </div>
                    @else
                        <p class="mt-2 text-sm text-muted">Nowhere to publish yet.</p>
                    @endif
                </div>
            </div>

            @if ($pipelineEmpty)
                <div class="mt-6">
                    <x-empty-state
                        heading="Nothing to chase yet"
                        message="Add a source and a destination. Michicast connects them into one quiet pipeline."
                    />
                </div>
            @endif
        </x-card>

        {{-- 3. Recent activity: next run first, then executions --}}
        <x-card heading="Recent activity" :kicker="$project->name">
            <x-slot:actions>
                <x-button href="{{ route('broadcast-runs.index', $project) }}" variant="ghost" :paw="false">All activity</x-button>
            </x-slot:actions>

            <ul class="flex flex-col divide-y divide-line border-t border-line">
                @if ($project->next_run_at)
                    <li class="flex flex-wrap items-center justify-between gap-4 py-4">
                        <div class="flex flex-col gap-1">
                            <p class="text-sm font-medium text-ink">Next run</p>
                            <p class="font-mono text-xs text-ink">
                                {{ $project->next_run_at->timezone($project->effectiveTimezone())->format('l, F j, Y \a\t g:i A T') }}
                                ({{ $project->effectiveTimezone() }})
                            </p>
                            <p class="text-sm text-muted">
                                Shown in {{ $project->effectiveTimezone() }}, the {{ $project->hasTimezoneOverride() ? 'broadcast override' : 'organization timezone' }}.
                            </p>
                        </div>
                    </li>
                @endif

                @forelse ($recentRuns as $run)
                    <li @class([
                        'flex flex-wrap items-center justify-between gap-4 py-4',
                        'bg-paper' => session('last_run_id') == $run->getKey(),
                    ])>
                        <div class="flex flex-col gap-1">
                            <a class="text-sm font-medium text-ink hover:text-accent" href="{{ route('broadcast-runs.show', [$project, $run]) }}">
                                <x-timestamp :value="$run->started_at" />
                            </a>
                            <p class="font-mono text-xs text-muted">
                                @if ($run->is_dry)
                                    Dry run ·
                                @endif
                                {{ $run->status->label() }}
                                · discovered {{ $run->items_found }}
                                · published {{ $run->items_delivered }}
                                · skipped {{ $run->items_skipped }}
                                · failed {{ $run->items_failed }}
                                @if ($run->finished_at && $run->started_at)
                                    · {{ $run->started_at->diffInSeconds($run->finished_at) }}s
                                @endif
                                · {{ ($run->finished_at ?? $run->started_at)->diffForHumans() }}
                            </p>
                        </div>
                        <x-button href="{{ route('broadcast-runs.show', [$project, $run]) }}" variant="ghost" :paw="false">Open</x-button>
                    </li>
                @empty
                    @unless ($project->next_run_at)
                        <li class="py-4 text-sm text-muted">This broadcast has never run.</li>
                    @endunless
                @endforelse
            </ul>
        </x-card>

        @can('update', $project)
            <dialog id="schedule-drawer" class="w-full max-w-md rounded-card border border-line bg-paper-raised p-0 text-ink shadow-md backdrop:bg-ink/40">
                <div class="flex flex-col gap-4 p-6">
                    <div class="flex items-start justify-between gap-3">
                        <h2 class="font-display text-xl tracking-tight">Edit schedule</h2>
                        <button type="button" class="rounded-md px-2 py-1 text-sm text-muted hover:bg-paper hover:text-ink" data-close-dialog>Close</button>
                    </div>
                    <p class="text-sm text-ink">
                        Runs are calculated in {{ $scheduleForm['timezoneName'] }}, the {{ $scheduleForm['timezoneSource'] }}.
                    </p>
                    <form class="flex flex-col gap-4" method="POST" action="{{ route('broadcast-schedules.update', $project) }}">
                        @csrf
                        @method('PATCH')
                        <x-select name="frequency" label="Frequency" :value="old('frequency', $scheduleForm['schedule']['frequency'] ?? 'daily')" :options="$scheduleForm['frequencies']" />
                        <x-input name="time" type="time" label="Time" :value="old('time', $scheduleForm['schedule']['time'] ?? '')" />
                        <fieldset class="flex flex-col gap-1.5">
                            <legend class="text-sm font-medium">Weekdays</legend>
                            <p class="text-sm text-muted">Used when the frequency is weekly.</p>
                            <div class="flex flex-col gap-1">
                                @foreach ($scheduleForm['weekdays'] as $value => $label)
                                    <label class="flex items-center gap-2 text-sm">
                                        <input
                                            type="checkbox"
                                            name="weekdays[]"
                                            value="{{ $value }}"
                                            @checked(in_array((string) $value, array_map('strval', old('weekdays', $scheduleForm['schedule']['weekdays'] ?? [])), true))
                                        >
                                        {{ $label }}
                                    </label>
                                @endforeach
                            </div>
                        </fieldset>
                        <x-input name="hours" type="number" label="Hours between runs" :value="old('hours', $scheduleForm['schedule']['hours'] ?? '')" min="1" max="168" />
                        <x-select
                            name="timezone"
                            label="Timezone"
                            :value="old('timezone', $scheduleForm['selectedTimezone'])"
                            :options="$scheduleForm['timezones']"
                            hint="Leave this on the organization timezone unless this broadcast should run on a different clock."
                        />
                        <div class="flex flex-wrap gap-2">
                            <x-button>Save schedule</x-button>
                            <x-button href="{{ route('broadcast-schedules.edit', $project) }}" variant="ghost" :paw="false">Open full editor</x-button>
                        </div>
                    </form>
                </div>
            </dialog>
        @endcan
    </div>
</x-layout>
