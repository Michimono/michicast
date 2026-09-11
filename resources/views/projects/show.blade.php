<x-layout :title="$project->name">
    <x-card :heading="$project->name" kicker="Projects" eared>
        <p class="font-mono text-xs text-muted">{{ $project->slug }}</p>

        @if ($project->description)
            <p class="text-sm text-ink">{{ $project->description }}</p>
        @endif

        @can('update', $project)
            <p>
                <x-button href="{{ route('projects.edit', $project) }}" variant="ghost" :paw="false">Edit project</x-button>
            </p>
        @endcan
    </x-card>
</x-layout>
