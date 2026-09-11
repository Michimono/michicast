<x-layout title="Projects">
    <x-card heading="Projects" kicker="Projects" eared>
        <p class="text-sm text-muted">Each project is one pipeline: sources, destinations and a schedule.</p>

        @can('create', [App\Models\Project::class, $organization])
            <p>
                <x-button href="{{ route('projects.create') }}">New project</x-button>
            </p>
        @endcan

        @if ($projects->isEmpty())
            <x-empty-state
                heading="Nothing to chase yet"
                message="A project holds one pipeline of sources, destinations and a schedule."
            />
        @else
            <ul class="flex flex-col divide-y divide-line border-t border-line">
                @foreach ($projects as $project)
                    <li class="flex flex-wrap items-center justify-between gap-4 py-4">
                        <div class="flex flex-col gap-0.5">
                            <a class="text-sm text-accent underline hover:no-underline" href="{{ route('projects.show', $project) }}">
                                {{ $project->name }}
                            </a>
                            <span class="font-mono text-xs text-muted">{{ $project->slug }}</span>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-card>
</x-layout>
