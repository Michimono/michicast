<x-layout title="Archived">
    <div class="flex flex-col gap-8">
        <div class="flex flex-col gap-1">
            <p class="font-mono text-[0.6875rem] tracking-[0.12em] text-muted uppercase">Workspace</p>
            <h1 class="font-display text-3xl tracking-tight">Archived</h1>
            <p class="max-w-prose text-sm text-muted">
                Broadcasts put aside for now. Open one to look around, or restore it when you need it again.
            </p>
        </div>

        @if ($projects->isEmpty())
            <x-empty-state
                heading="Nothing archived"
                message="When you archive a broadcast it will land here, quietly out of the way."
            />
        @else
            <x-card heading="Archived broadcasts" kicker="Archive" eared>
                <ul class="flex flex-col divide-y divide-line border-t border-line">
                    @foreach ($projects as $project)
                        <li class="flex flex-wrap items-center justify-between gap-4 py-4">
                            <div class="min-w-0 flex flex-col gap-1">
                                <a
                                    class="truncate text-sm font-medium text-ink hover:text-accent"
                                    href="{{ route('broadcasts.show', $project) }}"
                                >{{ $project->name }}</a>
                                <div class="flex flex-wrap items-center gap-2">
                                    <x-project-status :status="$project->status" />
                                    @if ($project->latestRun)
                                        <span class="font-mono text-xs text-muted">
                                            Last run {{ ($project->latestRun->finished_at ?? $project->latestRun->started_at)->diffForHumans() }}
                                        </span>
                                    @else
                                        <span class="font-mono text-xs text-muted">Never run</span>
                                    @endif
                                </div>
                            </div>

                            <div class="flex flex-wrap items-center gap-2">
                                <x-button
                                    href="{{ route('broadcasts.show', $project) }}"
                                    variant="ghost"
                                    :paw="false"
                                >Open</x-button>

                                @can('changeStatus', $project)
                                    @if ($project->canRestore())
                                        <form method="POST" action="{{ route('archived-broadcasts.destroy', $project) }}">
                                            @csrf
                                            @method('DELETE')
                                            <x-button>Restore</x-button>
                                        </form>
                                    @endif
                                @endcan
                            </div>
                        </li>
                    @endforeach
                </ul>
            </x-card>
        @endif
    </div>
</x-layout>
