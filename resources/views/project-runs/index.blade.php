<x-layout :title="$project->name.' runs'">
    <x-card heading="Run history" :kicker="$project->name" eared>
        <p class="text-sm text-muted">
            Newest first. Times are shown in your profile timezone
            ({{ auth()->user()->timezone }}). This project's schedule uses
            {{ $project->effectiveTimezone() }}.
        </p>

        <p>
            <x-button href="{{ route('broadcasts.show', $project) }}" variant="ghost" :paw="false">Back to project</x-button>
        </p>

        @if ($runs->isEmpty())
            <x-empty-state
                heading="No runs yet"
                message="When this project executes, each run will show up here with counts and outcomes."
            />
        @else
            <ul class="flex flex-col divide-y divide-line border-t border-line">
                @foreach ($runs as $run)
                    <li class="flex flex-wrap items-center justify-between gap-4 py-4">
                        <div class="flex flex-col gap-1">
                            <a class="text-sm font-medium text-ink hover:text-accent" href="{{ route('broadcast-runs.show', [$project, $run]) }}">
                                <x-timestamp :value="$run->started_at" />
                            </a>
                            <p class="font-mono text-xs text-muted">
                                @if ($run->is_dry)
                                    Dry run ·
                                @endif
                                {{ $run->status->label() }}
                                · found {{ $run->items_found }}
                                · new {{ $run->items_new }}
                                · skipped {{ $run->items_skipped }}
                                · {{ $run->is_dry ? 'would deliver' : 'delivered' }} {{ $run->items_delivered }}
                                · failed {{ $run->items_failed }}
                                @if ($run->started_at && $run->finished_at)
                                    · {{ $run->started_at->diffInSeconds($run->finished_at) }}s
                                @endif
                            </p>
                        </div>

                        <x-button href="{{ route('broadcast-runs.show', [$project, $run]) }}" variant="ghost" :paw="false">Open</x-button>
                    </li>
                @endforeach
            </ul>

            <div class="pt-4">
                {{ $runs->links() }}
            </div>
        @endif
    </x-card>
</x-layout>
