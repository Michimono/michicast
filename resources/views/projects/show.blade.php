<x-layout :title="$project->name">
    <x-card :heading="$project->name" kicker="Projects" eared>
        <div class="flex flex-wrap items-center gap-3">
            <p class="font-mono text-xs text-muted">{{ $project->slug }}</p>
            <x-project-status :status="$project->status" />
        </div>

        @if ($project->description)
            <p class="text-sm text-ink">{{ $project->description }}</p>
        @endif

        <div class="flex flex-wrap items-center gap-2">
            @can('update', $project)
                <x-button href="{{ route('projects.edit', $project) }}" variant="ghost" :paw="false">Edit project</x-button>
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
