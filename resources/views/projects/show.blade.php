<x-layout :title="$project->name">
    <div class="flex flex-col gap-8">
        {{-- 1. Project header --}}
        <x-card eared kicker="Overview">
            <div class="flex flex-col gap-4">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="min-w-0 flex flex-col gap-2">
                        @can('update', $project)
                            <form method="POST" action="{{ route('projects.update', $project) }}" class="flex flex-col gap-2" data-inline-edit>
                                @csrf
                                @method('PATCH')
                                <input
                                    type="text"
                                    name="name"
                                    value="{{ old('name', $project->name) }}"
                                    class="w-full max-w-xl border-0 bg-transparent p-0 font-display text-2xl tracking-tight text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
                                    aria-label="Project name"
                                    required
                                >
                                <textarea
                                    name="description"
                                    rows="2"
                                    placeholder="Short description"
                                    class="w-full max-w-2xl resize-y rounded-md border border-transparent bg-transparent px-0 py-1 text-sm text-ink placeholder:text-muted hover:border-line focus:border-line-strong focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
                                    aria-label="Project description"
                                >{{ old('description', $project->description) }}</textarea>
                                <div class="flex flex-wrap gap-2">
                                    <x-button type="submit" :paw="false" class="!px-3 !py-1.5 text-xs">Save details</x-button>
                                    <x-button href="{{ route('projects.edit', $project) }}" variant="ghost" :paw="false" class="!px-3 !py-1.5 text-xs">Edit project</x-button>
                                </div>
                            </form>
                        @else
                            <h1 class="font-display text-2xl tracking-tight">{{ $project->name }}</h1>
                            @if ($project->description)
                                <p class="max-w-2xl text-sm text-ink">{{ $project->description }}</p>
                            @endif
                        @endcan

                        <div class="flex flex-wrap items-center gap-2">
                            <x-project-status :status="$project->status" />
                            <x-project-health :health="$health" />
                            <p class="font-mono text-xs text-muted">{{ $project->slug }}</p>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        @can('markDue', $project)
                            @if ($project->canMarkDue())
                                <form method="POST" action="{{ route('immediate-project-runs.store', $project) }}" data-run-form>
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
                                <form
                                    method="POST"
                                    action="{{ route('archived-projects.store', $project) }}"
                                    onsubmit="return confirm('Archive this project? Historical activity is kept.')"
                                >
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
            </div>
        </x-card>

        {{-- 2. Next run / schedule --}}
        <x-card heading="Next run" kicker="Schedule">
            <dl class="grid gap-3 sm:grid-cols-2">
                <div>
                    <dt class="text-xs font-medium tracking-wide text-muted uppercase">Next execution</dt>
                    <dd class="text-sm text-ink">
                        @if ($project->next_run_at)
                            <span class="font-mono">
                                {{ $project->next_run_at->timezone($project->effectiveTimezone())->format('l, F j, Y \a\t g:i A T') }}
                                ({{ $project->effectiveTimezone() }})
                            </span>
                            <p class="mt-1 text-sm text-muted">
                                Shown in {{ $project->effectiveTimezone() }}, the {{ $project->hasTimezoneOverride() ? 'project override' : 'organization timezone' }}.
                            </p>
                        @else
                            <span class="text-muted">This project has no schedule yet.</span>
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-xs font-medium tracking-wide text-muted uppercase">Timezone</dt>
                    <dd class="font-mono text-sm text-ink">{{ $project->effectiveTimezone() }}</dd>
                </div>
            </dl>

            @can('update', $project)
                <div class="mt-3">
                    <x-button type="button" variant="ghost" :paw="false" data-open-dialog="schedule-drawer">Edit schedule</x-button>
                </div>

                <dialog id="schedule-drawer" class="w-full max-w-md rounded-card border border-line bg-paper-raised p-0 text-ink shadow-md backdrop:bg-ink/40">
                    <div class="flex flex-col gap-4 p-6">
                        <div class="flex items-start justify-between gap-3">
                            <h2 class="font-display text-xl tracking-tight">Edit schedule</h2>
                            <button type="button" class="rounded-md px-2 py-1 text-sm text-muted hover:bg-paper hover:text-ink" data-close-dialog>Close</button>
                        </div>
                        <p class="text-sm text-ink">
                            Runs are calculated in {{ $scheduleForm['timezoneName'] }}, the {{ $scheduleForm['timezoneSource'] }}.
                        </p>
                        <form class="flex flex-col gap-4" method="POST" action="{{ route('project-schedules.update', $project) }}">
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
                                hint="Leave this on the organization timezone unless this project should run on a different clock."
                            />
                            <div class="flex flex-wrap gap-2">
                                <x-button>Save schedule</x-button>
                                <x-button href="{{ route('project-schedules.edit', $project) }}" variant="ghost" :paw="false">Open full editor</x-button>
                            </div>
                        </form>
                    </div>
                </dialog>
            @endcan
        </x-card>

        {{-- 3. Broadcasts --}}
        <x-card heading="Broadcasts" kicker="Routes">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <p class="text-sm text-muted">Sources → optional filter/transform → destinations.</p>
                @can('create', [App\Models\Broadcast::class, $project])
                    <x-button href="{{ route('project-broadcasts.create', $project) }}" :paw="false">Add broadcast</x-button>
                @endcan
            </div>

            @if ($broadcasts->isEmpty())
                <p class="text-sm text-muted">No broadcasts yet.</p>
            @else
                <ul class="flex flex-col divide-y divide-line border-t border-line">
                    @foreach ($broadcasts as $broadcast)
                        <li class="flex flex-col gap-3 py-4">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="min-w-0 flex flex-col gap-1">
                                    <a class="truncate text-sm font-medium text-ink hover:text-accent" href="{{ route('broadcasts.edit', $broadcast) }}">{{ $broadcast->name }}</a>
                                    <p class="font-mono text-xs text-muted">
                                        {{ $broadcast->source?->name ?? 'Source' }}
                                        →
                                        @if (! empty($broadcast->filter['rules'] ?? null))
                                            Filter →
                                        @endif
                                        @if (! empty($broadcast->transform['template'] ?? null) || ! empty($broadcast->transform['rules'] ?? null))
                                            Transform →
                                        @endif
                                        {{ $broadcast->destination?->name ?? 'Destination' }}
                                    </p>
                                    <x-broadcast-status :broadcast="$broadcast" />
                                </div>
                                <div class="flex flex-wrap gap-2">
                                    <x-button href="{{ route('broadcasts.edit', $broadcast) }}" variant="ghost" :paw="false">Edit</x-button>
                                    <x-button href="{{ route('content-previews.store', $broadcast) }}" variant="ghost" :paw="false" onclick="event.preventDefault(); this.closest('li').querySelector('[data-preview-form]')?.requestSubmit();">Preview</x-button>
                                    <form method="POST" action="{{ route('content-previews.store', $broadcast) }}" data-preview-form class="hidden">@csrf</form>
                                    @can('changeStatus', $broadcast)
                                        @if ($broadcast->canDisable())
                                            <form method="POST" action="{{ route('enabled-broadcasts.destroy', $broadcast) }}">
                                                @csrf
                                                @method('DELETE')
                                                <x-button variant="ghost" :paw="false">Disable</x-button>
                                            </form>
                                        @elseif ($broadcast->canEnable())
                                            <form method="POST" action="{{ route('enabled-broadcasts.store', $broadcast) }}">
                                                @csrf
                                                <x-button variant="ghost" :paw="false">Enable</x-button>
                                            </form>
                                        @endif
                                    @endcan
                                    @can('changeStatus', $broadcast)
                                        @if (! $broadcast->isArchived())
                                            <form method="POST" action="{{ route('archived-broadcasts.store', $broadcast) }}" onsubmit="return confirm('Archive this broadcast?')">
                                                @csrf
                                                <x-button variant="ghost" :paw="false">Archive</x-button>
                                            </form>
                                        @endif
                                    @endcan
                                </div>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif

            <p class="mt-3">
                <x-button href="{{ route('project-broadcasts.index', $project) }}" variant="ghost" :paw="false">Manage broadcasts</x-button>
            </p>
        </x-card>

        {{-- 4. Sources and destinations --}}
        <div class="grid gap-6 lg:grid-cols-2">
            <x-card heading="Sources" kicker="Inputs">
                <p class="font-mono text-2xl text-ink">{{ $project->sources_count }}</p>
                @if ($sources->isEmpty())
                    <p class="mt-2 text-sm text-muted">No sources yet.</p>
                @else
                    <ul class="mt-3 flex flex-col gap-2">
                        @foreach ($sources as $source)
                            <li class="flex flex-wrap items-center justify-between gap-2 rounded-md border border-line bg-paper px-3 py-2">
                                <div class="min-w-0">
                                    <a class="truncate text-sm text-ink hover:text-accent" href="{{ route('sources.edit', $source) }}">{{ $source->name }}</a>
                                    <div class="mt-1 flex flex-wrap gap-2">
                                        <x-source-status :source="$source" />
                                        @if ($source->last_tested_at)
                                            <span class="font-mono text-[0.6875rem] text-muted">
                                                {{ $source->last_test_successful ? 'Test ok' : 'Test failed' }}
                                            </span>
                                        @endif
                                    </div>
                                </div>
                                <x-button href="{{ route('sources.edit', $source) }}" variant="ghost" :paw="false">Manage</x-button>
                            </li>
                        @endforeach
                    </ul>
                @endif
                <div class="mt-3 flex flex-wrap gap-2">
                    <x-button href="{{ route('project-sources.index', $project) }}" variant="ghost" :paw="false">All sources</x-button>
                    @can('create', [App\Models\Source::class, $project])
                        <x-button href="{{ route('project-sources.create', $project) }}" :paw="false">Add a source</x-button>
                    @endcan
                </div>
            </x-card>

            <x-card heading="Destinations" kicker="Outputs">
                <p class="font-mono text-2xl text-ink">{{ $project->destinations_count }}</p>
                @if ($destinations->isEmpty())
                    <p class="mt-2 text-sm text-muted">No destinations yet.</p>
                @else
                    <ul class="mt-3 flex flex-col gap-2">
                        @foreach ($destinations as $destination)
                            <li class="flex flex-wrap items-center justify-between gap-2 rounded-md border border-line bg-paper px-3 py-2">
                                <div class="min-w-0">
                                    <a class="truncate text-sm text-ink hover:text-accent" href="{{ route('destinations.edit', $destination) }}">{{ $destination->name }}</a>
                                    <div class="mt-1 flex flex-wrap gap-2">
                                        <x-destination-status :destination="$destination" />
                                        @if ($destination->last_tested_at)
                                            <span class="font-mono text-[0.6875rem] text-muted">
                                                {{ $destination->last_test_successful ? 'Test ok' : 'Test failed' }}
                                            </span>
                                        @endif
                                    </div>
                                </div>
                                <x-button href="{{ route('destinations.edit', $destination) }}" variant="ghost" :paw="false">Manage</x-button>
                            </li>
                        @endforeach
                    </ul>
                @endif
                <div class="mt-3 flex flex-wrap gap-2">
                    <x-button href="{{ route('project-destinations.index', $project) }}" variant="ghost" :paw="false">All destinations</x-button>
                    @can('create', [App\Models\Destination::class, $project])
                        <x-button href="{{ route('project-destinations.create', $project) }}" :paw="false">Add destination</x-button>
                    @endcan
                </div>
            </x-card>
        </div>

        @if ($isEmpty)
            <x-empty-state
                heading="Nothing to chase yet"
                message="Add a source so this project has somewhere to read from. Destinations and broadcasts come next."
            >
                @can('create', [App\Models\Source::class, $project])
                    <x-button href="{{ route('project-sources.create', $project) }}">Add a source</x-button>
                @endcan
            </x-empty-state>
        @endif

        {{-- 5. Recent activity --}}
        <x-card heading="Recent activity" :kicker="$project->name">
            @if ($recentRuns->isEmpty())
                <p class="text-sm text-muted">This project has never run.</p>
            @else
                <ul class="flex flex-col divide-y divide-line border-t border-line">
                    @foreach ($recentRuns as $run)
                        <li @class([
                            'flex flex-wrap items-center justify-between gap-4 py-4',
                            'bg-paper' => session('last_run_id') == $run->getKey(),
                        ])>
                            <div class="flex flex-col gap-1">
                                <a class="text-sm font-medium text-ink hover:text-accent" href="{{ route('project-runs.show', [$project, $run]) }}">
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
                            <x-button href="{{ route('project-runs.show', [$project, $run]) }}" variant="ghost" :paw="false">Open</x-button>
                        </li>
                    @endforeach
                </ul>
            @endif

            <p class="mt-3">
                <x-button href="{{ route('project-runs.index', $project) }}" variant="ghost" :paw="false">All activity</x-button>
            </p>
        </x-card>
    </div>
</x-layout>