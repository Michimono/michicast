<x-layout title="Broadcasts">
    <x-card heading="Broadcasts" kicker="Broadcasts" eared>
        <p class="text-sm text-muted">Each broadcast is one pipeline: a source, a destination and a schedule.</p>

        @can('create', [App\Models\Project::class, $organization])
            <p>
                <x-button href="{{ route('broadcasts.create') }}">New broadcast</x-button>
            </p>
        @endcan

        @if ($projects->isEmpty())
            <x-empty-state
                heading="Nothing to chase yet"
                message="A broadcast holds one source, one destination and a schedule."
            />
        @else
            <ul class="flex flex-col divide-y divide-line border-t border-line">
                @foreach ($projects as $project)
                    <li class="flex flex-wrap items-center justify-between gap-4 py-4">
                        <div class="flex flex-col gap-0.5">
                            <a class="text-sm text-accent underline hover:no-underline" href="{{ route('broadcasts.show', $project) }}">
                                {{ $project->name }}
                            </a>
                            <span class="font-mono text-xs text-muted">{{ $project->slug }}</span>
                        </div>

                        <x-project-status :status="$project->status" />
                    </li>
                @endforeach
            </ul>
        @endif
    </x-card>
</x-layout>
