<x-layout title="Settings · {{ $project->name }}">
    <div class="mx-auto flex w-full max-w-lg flex-col gap-8">
        <x-card id="general" heading="General" eared class="scroll-mt-8">
            <p class="text-sm text-ink">
                Rename the broadcast and describe what it does. The slug stays {{ $project->slug }}.
            </p>

            <form class="flex flex-col gap-4" method="POST" action="{{ route('broadcasts.update', $project) }}">
                @csrf
                @method('PATCH')

                <x-input name="name" label="Broadcast name" :value="$project->name" autofocus required />

                <x-textarea name="description" label="Description" :value="$project->description" />

                <div class="border-t border-line pt-4">
                    <h2 class="mb-3 font-display text-lg tracking-tight">Schedule</h2>
                    <x-schedule-fields
                        :frequencies="$frequencies"
                        :weekdays="$weekdays"
                        :timezones="$timezones"
                        :schedule="$schedule"
                        :selected-timezone="$selectedTimezone"
                        :timezone-name="$timezoneName"
                        :timezone-source="$timezoneSource"
                    />
                </div>

                <div class="flex flex-wrap gap-2">
                    <x-button
                        variant="ghost"
                        :paw="false"
                        formaction="{{ route('broadcast-schedule-previews.store', $project) }}"
                    >Preview upcoming runs</x-button>
                    <x-button>Save</x-button>
                </div>
            </form>
        </x-card>

        <x-card id="upcoming" heading="Upcoming runs" kicker="Preview" class="scroll-mt-8">
            @if ($upcoming === [])
                <p class="text-sm text-muted">Save a complete schedule, or preview it, to see the next runs.</p>
            @else
                <p class="text-sm text-muted">Shown in {{ $timezoneName }}, the {{ $timezoneSource }}.</p>
                <ul class="flex flex-col divide-y divide-line border-t border-line">
                    @foreach ($upcoming as $run)
                        <li class="font-mono text-sm py-3">{{ $run }}</li>
                    @endforeach
                </ul>
            @endif
        </x-card>

        <x-card id="operations" heading="Operations" kicker="Ops" class="scroll-mt-8">
            <p class="text-sm text-muted">
                Rehearse a run without publishing, clear delivery state so Run now can try again, or publish only the latest received item now (bypasses backfill and already-delivered blockers for that item alone).
            </p>

            <div class="flex flex-wrap items-center gap-2">
                @can('dryRun', $project)
                    <form method="POST" action="{{ route('broadcast-dry-runs.store', $project) }}" data-run-form>
                        @csrf
                        <x-button variant="ghost" :paw="false" data-run-submit>Dry run</x-button>
                    </form>
                @endcan

                @can('allowLatestResend', $project)
                    <form
                        method="POST"
                        action="{{ route('broadcast-latest-resends.store', $project) }}"
                        onsubmit="return confirm('Clear delivery state for the latest received item? It will not publish until you use Run now.')"
                    >
                        @csrf
                        <x-button variant="ghost" :paw="false">Allow resend of latest</x-button>
                    </form>
                @endcan

                @can('sendLatest', $project)
                    <form
                        method="POST"
                        action="{{ route('broadcast-send-latests.store', $project) }}"
                        data-run-form
                        onsubmit="return confirm('Publish only the latest received item to the destination now? This bypasses backfill and already-delivered blockers for that item alone.')"
                    >
                        @csrf
                        <x-button variant="ghost" :paw="false" data-run-submit>Send latest</x-button>
                    </form>
                @endcan
            </div>

            @can('changeStatus', $project)
                @if ($project->canActivate())
                    <div class="border-t border-line pt-4">
                        <p class="mb-2 text-sm text-muted">This broadcast is still a draft.</p>
                        <form method="POST" action="{{ route('active-broadcasts.store', $project) }}">
                            @csrf
                            <x-button>Activate</x-button>
                        </form>
                    </div>
                @endif
            @endcan

            <div hidden data-run-progress class="rounded-md border border-line bg-paper px-4 py-3">
                <p class="text-sm text-ink">Running…</p>
                <div class="mt-2 flex items-center gap-2" aria-hidden="true">
                    <x-icons.paw class="size-4 house-text" />
                    <x-icons.paw class="size-4 text-muted" />
                    <x-icons.paw class="size-4 text-muted" />
                </div>
            </div>
        </x-card>

        @can('changeStatus', $project)
            @if ($project->canArchive() || $project->canRestore())
                <x-card id="archive" heading="Archive" kicker="Danger zone" class="scroll-mt-8">
                    <p class="text-sm text-muted">
                        Archiving hides this broadcast from the workspace. Historical activity is kept.
                    </p>
                    <div class="flex flex-wrap items-center gap-2">
                        @if ($project->canArchive())
                            <form
                                method="POST"
                                action="{{ route('archived-broadcasts.store', $project) }}"
                                onsubmit="return confirm('Archive this broadcast? Historical activity is kept.')"
                            >
                                @csrf
                                <input type="hidden" name="confirmation" value="1">
                                <x-button variant="ghost" :paw="false">Archive</x-button>
                            </form>
                        @endif

                        @if ($project->canRestore())
                            <form method="POST" action="{{ route('archived-broadcasts.destroy', $project) }}">
                                @csrf
                                @method('DELETE')
                                <x-button>Restore</x-button>
                            </form>
                        @endif
                    </div>
                </x-card>
            @endif
        @endcan
    </div>
</x-layout>