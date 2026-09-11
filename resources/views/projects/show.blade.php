<x-layout :title="$project->name">
    <x-card :heading="$project->name" kicker="Projects" eared>
        <div class="flex flex-wrap items-center gap-3">
            <p class="font-mono text-xs text-muted">{{ $project->slug }}</p>
            <x-project-status :status="$project->status" />
        </div>

        @if ($project->description)
            <p class="text-sm text-ink">{{ $project->description }}</p>
        @endif

        @if ($project->next_run_at)
            <p class="font-mono text-sm text-ink">
                Next run {{ $project->next_run_at->timezone($project->effectiveTimezone())->format('l, F j, Y \a\t g:i A T') }}
            </p>
            <p class="text-sm text-muted">
                Shown in {{ $project->effectiveTimezone() }}, the {{ $project->hasTimezoneOverride() ? 'project override' : 'organization timezone' }}.
            </p>
        @else
            <p class="text-sm text-muted">This project has no schedule yet.</p>
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
</x-layout>
