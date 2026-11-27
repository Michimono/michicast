<x-layout title="Broadcasts">
    <x-page-header
        :kicker="$organization->name"
        heading="Broadcasts"
        lead="Each broadcast is one pipeline: a source, a destination and a schedule."
    >
        @can('create', [App\Models\Project::class, $organization])
            <x-slot:actions>
                <x-button href="{{ route('broadcasts.create') }}">New broadcast</x-button>
            </x-slot:actions>
        @endcan
    </x-page-header>

    <x-card eared>
        @if ($projects->isEmpty())
            <x-empty-state
                heading="Nothing to chase yet"
                message="A broadcast holds one source, one destination and a schedule."
            />
        @else
            <ul class="flex flex-col divide-y divide-line">
                @foreach ($projects as $project)
                    <li class="flex flex-wrap items-center justify-between gap-4 py-4">
                        <div class="flex flex-col gap-0.5">
                            <a class="text-sm text-accent underline hover:no-underline" href="{{ route('broadcasts.show', $project) }}">
                                {{ $project->name }}
                            </a>
                            <span class="font-mono text-xs text-muted">{{ $project->slug }} &middot; {{ $project->kind->shortLabel() }}</span>
                        </div>

                        <x-project-status :status="$project->status" />
                    </li>
                @endforeach
            </ul>
        @endif
    </x-card>
</x-layout>
