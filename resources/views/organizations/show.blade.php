<x-layout :title="$organization->name">
    <div class="flex flex-col gap-8">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div class="flex flex-col gap-1">
                <p class="font-mono text-[0.6875rem] tracking-[0.12em] text-muted uppercase">Workspace</p>
                <h1 class="font-display text-3xl tracking-tight">{{ $organization->name }}</h1>
                <p class="max-w-prose text-sm text-muted">
                    Your broadcasts at a glance. Open a card to manage a pipeline, or start a new one.
                </p>
            </div>

            @can('create', [App\Models\Project::class, $organization])
                <x-button href="{{ route('broadcasts.create', $organization) }}">+ New broadcast</x-button>
            @endcan
        </div>

        @if ($projects->isEmpty())
            <x-empty-state
                heading="Nothing to chase yet"
                message="Create a broadcast and this workspace becomes a row of cards you can scan and steer."
            >
                @can('create', [App\Models\Project::class, $organization])
                    <x-button href="{{ route('broadcasts.create', $organization) }}">+ New broadcast</x-button>
                @endcan
            </x-empty-state>
        @else
            <ul class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($projects as $project)
                    <li class="relative z-0 has-[details[open]]:z-30">
                        <article class="group relative flex h-full flex-col gap-4 rounded-card border border-line bg-paper-raised p-5 transition-colors hover:border-line-strong">
                            <x-card-ears />

                            <div class="relative flex items-start justify-between gap-3">
                                <div class="min-w-0 flex flex-col gap-2">
                                    <div class="flex items-center gap-2 text-muted" title="Broadcast">
                                        <x-icons.antenna class="size-4 shrink-0" />
                                        <span class="font-mono text-[0.625rem] tracking-[0.12em] uppercase">Broadcast</span>
                                    </div>
                                    <h2 class="truncate font-display text-xl tracking-tight">
                                        <a
                                            href="{{ route('broadcasts.show', $project) }}"
                                            class="text-ink hover:text-accent focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
                                        >{{ $project->name }}</a>
                                    </h2>
                                    <x-project-status :status="$project->status" />
                                </div>

                                @can('changeStatus', $project)
                                    <details class="relative shrink-0" data-project-menu>
                                        <summary
                                            class="flex size-9 cursor-pointer list-none items-center justify-center rounded-md text-muted transition-colors hover:bg-paper hover:text-ink [&::-webkit-details-marker]:hidden"
                                            aria-label="Actions for {{ $project->name }}"
                                        >
                                            <x-icons.ellipsis class="size-5" />
                                        </summary>

                                        <div class="absolute right-0 z-40 mt-1 w-44 overflow-hidden rounded-md border border-line bg-paper-raised py-1 shadow-md">
                                            @can('update', $project)
                                                <a
                                                    href="{{ route('broadcasts.edit', $project) }}"
                                                    class="block px-3 py-2 text-sm text-ink hover:bg-paper"
                                                >Settings</a>
                                            @endcan

                                            @if ($project->canPause())
                                                <form method="POST" action="{{ route('paused-broadcasts.store', $project) }}">
                                                    @csrf
                                                    <input type="hidden" name="return" value="organization">
                                                    <button class="block w-full px-3 py-2 text-left text-sm text-ink hover:bg-paper" type="submit">
                                                        Pause
                                                    </button>
                                                </form>
                                            @endif

                                            @if ($project->canResume())
                                                <form method="POST" action="{{ route('paused-broadcasts.destroy', $project) }}">
                                                    @csrf
                                                    @method('DELETE')
                                                    <input type="hidden" name="return" value="organization">
                                                    <button class="block w-full px-3 py-2 text-left text-sm text-ink hover:bg-paper" type="submit">
                                                        Resume
                                                    </button>
                                                </form>
                                            @endif

                                            @if ($project->canArchive())
                                                <form
                                                    method="POST"
                                                    action="{{ route('archived-broadcasts.store', $project) }}"
                                                    onsubmit="return confirm('Archive this broadcast? Historical activity is kept.')"
                                                >
                                                    @csrf
                                                    <input type="hidden" name="confirmation" value="1">
                                                    <input type="hidden" name="return" value="organization">
                                                    <button class="block w-full px-3 py-2 text-left text-sm text-ink hover:bg-paper" type="submit">
                                                        Archive
                                                    </button>
                                                </form>
                                            @endif
                                        </div>
                                    </details>
                                @endcan
                            </div>

                            <div class="mt-auto flex flex-col gap-3 border-t border-line pt-3">
                                <div class="flex flex-col gap-2">
                                    <div>
                                        <p class="text-[0.6875rem] font-medium tracking-wide text-muted uppercase">Next run</p>
                                        <p class="font-mono text-xs text-ink">
                                            @if ($project->next_run_at)
                                                {{ $project->next_run_at->timezone($project->effectiveTimezone())->format('M j, Y g:i A T') }}
                                                <span class="text-muted">({{ $project->effectiveTimezone() }})</span>
                                            @else
                                                <span class="font-sans text-muted">No schedule yet</span>
                                            @endif
                                        </p>
                                    </div>
                                    <div>
                                        <p class="text-[0.6875rem] font-medium tracking-wide text-muted uppercase">Last run</p>
                                        <p class="text-sm text-ink">
                                            @if ($project->latestRun)
                                                {{ $project->latestRun->status->label() }}
                                                @if ($project->latestRun->is_dry)
                                                    <span class="font-mono text-xs text-muted">(dry run)</span>
                                                @endif
                                                &middot;
                                                <span class="font-mono text-xs text-muted">
                                                    {{ ($project->latestRun->finished_at ?? $project->latestRun->started_at)->diffForHumans() }}
                                                </span>
                                            @else
                                                <span class="text-muted">Never run</span>
                                            @endif
                                        </p>
                                    </div>
                                </div>

                                <x-button
                                    variant="ghost"
                                    href="{{ route('broadcasts.show', $project) }}"
                                    class="self-start"
                                >View broadcast</x-button>
                            </div>
                        </article>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</x-layout>
