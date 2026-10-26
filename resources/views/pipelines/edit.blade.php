<x-editor-layout :title="'Pipeline · '.$project->name">
    <x-slot:bar>
        {{-- Back: one comfortable control that leaves the editor. --}}
        <a
            class="flex h-9 shrink-0 items-center gap-1.5 rounded-md px-2 text-muted transition-colors hover:bg-paper hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
            href="{{ route('organizations.show', $project->organization) }}"
            title="Back to {{ $project->organization->name }}"
        >
            <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 6l-6 6 6 6" /></svg>
            <span class="sr-only">Back to {{ $project->organization->name }}</span>
            <x-brand.mark variant="face" class="h-5" />
            <span class="hidden font-display text-lg tracking-tight text-ink lg:inline">{{ config('app.name') }}</span>
        </a>

        <span class="hidden h-5 w-px shrink-0 bg-line sm:block" aria-hidden="true"></span>

        {{-- Identity: where you are, what kind of editor this is, and the broadcast's name. --}}
        <div class="flex min-w-0 flex-1 items-center gap-3 sm:gap-4">
            <div class="flex min-w-0 flex-col justify-center leading-tight">
                <p class="flex items-center gap-1.5 font-mono text-[0.5625rem] tracking-[0.14em] uppercase">
                    <span class="hidden truncate text-muted md:inline">{{ $project->organization->name }}</span>
                    <span class="hidden text-muted md:inline" aria-hidden="true">/</span>
                    <span class="text-node-transform">{{ $project->kind->label() }}</span>
                </p>
                <h1 class="min-w-0 truncate font-display text-base tracking-tight text-ink sm:text-lg">
                    @can('update', $project)
                        <button
                            type="button"
                            class="block max-w-full cursor-pointer truncate rounded-sm text-left hover:text-accent focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
                            data-open-panel="settings"
                            data-focus-field="name"
                            title="Rename this broadcast"
                        >
                            <span data-pipeline-name>{{ $project->name }}</span>
                        </button>
                    @else
                        <span data-pipeline-name>{{ $project->name }}</span>
                    @endcan
                </h1>
            </div>

            {{-- Status group: lifecycle, document state, validation. --}}
            <div class="flex shrink-0 items-center gap-2" data-pipeline-status-group>
                <x-project-status :status="$project->status" class="hidden sm:inline-flex" data-pipeline-status />

                <p
                    class="inline-flex items-center gap-1.5 font-mono text-xs text-muted"
                    data-pipeline-save-status
                    data-state="{{ $editor['can_edit'] ? 'saved' : 'readonly' }}"
                    aria-live="polite"
                    title="{{ $editor['can_edit'] ? 'All changes are saved' : 'You can look, not edit' }}"
                >
                    <span class="pipeline-save-dot inline-block size-1.5 rounded-full" aria-hidden="true"></span>
                    <span class="hidden lg:inline" data-pipeline-save-text>{{ $editor['can_edit'] ? 'Saved' : 'Read only' }}</span>
                </p>

                <button
                    type="button"
                    class="inline-flex shrink-0 cursor-pointer items-center gap-1.5 rounded-full border px-2.5 py-0.5 font-mono text-[0.6875rem] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
                    data-pipeline-issues
                    data-open-panel="issues"
                    aria-pressed="false"
                    title="Show validation issues"
                    hidden
                >
                    <span data-pipeline-issues-count>0</span>
                    <span class="hidden sm:inline" data-pipeline-issues-label>issues</span>
                </button>
            </div>
        </div>

        {{-- Primary actions: test, run, play or pause. Each posts to its own resource. --}}
        <div class="flex shrink-0 items-center justify-start gap-2" role="toolbar" aria-label="Broadcast actions">
            @can('dryRun', $project)
                <button
                    type="button"
                    class="inline-flex h-8 cursor-pointer items-center gap-1.5 rounded-md border border-line-strong px-2.5 text-sm font-medium text-ink hover:border-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent disabled:cursor-not-allowed disabled:opacity-50"
                    data-pipeline-test
                    data-open-panel="test"
                    title="Test: fetch the sources and render every message without sending anything"
                >
                    <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 3h6M10 3v5.5L4.5 18a2 2 0 0 0 1.7 3h11.6a2 2 0 0 0 1.7-3L14 8.5V3" /><path d="M8 15h8" /></svg>
                    <span class="hidden md:inline">Test</span>
                </button>
            @endcan

            {{-- Rendered again by the script whenever the broadcast's state changes. --}}
            <div class="flex items-center gap-1.5" data-pipeline-lifecycle>
                @can('markDue', $project)
                    @if ($project->canMarkDue())
                        <form method="POST" action="{{ route('immediate-broadcast-runs.store', $project) }}" data-lifecycle-form="run-now">
                            @csrf
                            <button
                                type="submit"
                                class="inline-flex h-8 cursor-pointer items-center gap-1.5 rounded-md border border-line-strong px-2.5 text-sm font-medium text-ink hover:border-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent disabled:cursor-not-allowed disabled:opacity-50"
                                title="Run now: fetch and deliver on the live routes"
                            >
                                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 12a8 8 0 1 1-2.3-5.6" /><path d="M20 4v5h-5" /></svg>
                                <span class="hidden md:inline">Run now</span>
                            </button>
                        </form>
                    @endif
                @endcan

                @can('changeStatus', $project)
                    @if ($project->status === App\Enums\ProjectStatus::Draft)
                        @if ($project->canActivate())
                            <form method="POST" action="{{ route('active-broadcasts.store', $project) }}" data-lifecycle-form="activate">
                                @csrf
                                <x-button class="!h-8 !px-3 !py-0">Play</x-button>
                            </form>
                        @else
                            <x-tooltip :text="$project->activationBlockReason()" id="play-disabled-reason" align="end">
                                <x-button disabled aria-disabled="true" :paw="false" type="button" tabindex="-1" class="!h-8 !px-3 !py-0">Play</x-button>
                            </x-tooltip>
                        @endif
                    @elseif ($project->canPause())
                        <form method="POST" action="{{ route('paused-broadcasts.store', $project) }}" data-lifecycle-form="pause">
                            @csrf
                            <x-button variant="ghost" :paw="false" class="!h-8 !px-3 !py-0">Pause</x-button>
                        </form>
                    @elseif ($project->canResume())
                        <form method="POST" action="{{ route('paused-broadcasts.destroy', $project) }}" data-lifecycle-form="resume">
                            @csrf
                            @method('DELETE')
                            <x-button class="!h-8 !px-3 !py-0">Play</x-button>
                        </form>
                    @endif
                @endcan
            </div>
        </div>

        <span class="mx-0.5 h-5 w-px shrink-0 bg-line sm:mx-1" aria-hidden="true"></span>

        {{-- Utilities: open in the inspector; the full pages stay one link away. --}}
        <div class="flex shrink-0 items-center gap-0.5">
            <button
                type="button"
                class="grid size-9 cursor-pointer place-items-center rounded-md text-muted transition-colors hover:bg-paper hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent aria-pressed:bg-paper aria-pressed:text-ink"
                data-open-panel="activity"
                aria-pressed="false"
                title="Activity"
            >
                <x-icons.activity class="size-5" />
                <span class="sr-only">Activity</span>
            </button>

            @can('update', $project)
                <button
                    type="button"
                    class="grid size-9 cursor-pointer place-items-center rounded-md text-muted transition-colors hover:bg-paper hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent aria-pressed:bg-paper aria-pressed:text-ink"
                    data-open-panel="settings"
                    aria-pressed="false"
                    title="Settings"
                >
                    <x-icons.cog class="size-5" />
                    <span class="sr-only">Settings</span>
                </button>
            @endcan
        </div>
    </x-slot:bar>

    @if ($project->organization->isSuspended())
        <div class="absolute inset-x-0 top-0 z-30 px-4 pt-3">
            <x-organization-suspended-banner :organization="$project->organization" />
        </div>
    @elseif ($project->organization->isPendingApproval())
        <div class="absolute inset-x-0 top-0 z-30 px-4 pt-3">
            <x-organization-pending-banner :organization="$project->organization" />
        </div>
    @endif

    <div class="absolute inset-0" data-pipeline-editor='@json($editor)'>
        {{-- The world: edges in SVG under HTML nodes, both inside one transformed layer. --}}
        <div
            class="pipeline-canvas"
            data-canvas
            tabindex="0"
            role="application"
            aria-label="Pipeline canvas"
            aria-describedby="pipeline-canvas-help"
        >
            <div class="pipeline-world" data-world>
                <svg class="pipeline-edges" data-edges width="1" height="1" aria-hidden="true">
                    <g data-edge-layer></g>
                    <path class="pipeline-edge is-draft" data-draft-edge hidden d="" />
                    <path class="pipeline-arrowhead is-draft" data-draft-arrow hidden d="" />
                </svg>
                <div class="pipeline-nodes" data-nodes></div>
            </div>
        </div>

        <p id="pipeline-canvas-help" class="sr-only">
            Drag the background to pan, hold Control and scroll to zoom. Drag a handle on a node onto another node to connect them, or drag a node type from the toolbox onto the canvas. Delete removes the selection; Control Z undoes.
        </p>

        {{-- Toolbox: the only way nodes come into being. Click adds near the selection; drag places. --}}
        @if ($editor['can_edit'])
            <button
                type="button"
                class="absolute top-4 left-4 z-20 grid size-10 cursor-pointer place-items-center rounded-lg border border-line bg-paper-raised text-ink shadow-sm hover:border-line-strong focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent md:hidden"
                data-palette-toggle
                aria-expanded="false"
                aria-controls="pipeline-palette"
                title="Add node"
            >
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14" /></svg>
                <span class="sr-only">Add node</span>
            </button>

            <nav id="pipeline-palette" class="pipeline-palette absolute top-4 left-4 z-20 flex-col gap-1 rounded-lg border border-line bg-paper-raised p-1.5 shadow-sm" aria-label="Add node" data-palette>
                <div class="flex items-center justify-between gap-2 px-2 pt-1 pb-1.5">
                    <p class="font-mono text-[0.5625rem] tracking-[0.14em] text-muted uppercase">Add</p>
                    <button type="button" class="-mr-1 grid size-6 cursor-pointer place-items-center rounded-md text-muted hover:bg-paper hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-accent md:hidden" data-palette-toggle aria-label="Close toolbox">
                        <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18" /></svg>
                    </button>
                </div>
                @foreach ([
                    ['source', 'Source', 'source', 'Where items come from'],
                    ['filter', 'Filter', 'transform', 'Keep only matching items'],
                    ['transform', 'Transform', 'transform', 'Change a field'],
                    ['mapping', 'Mapping', 'transform', 'Choose which fields go where'],
                    ['destination', 'Destination', 'destination', 'Where messages go'],
                ] as [$type, $label, $category, $hint])
                    <button
                        type="button"
                        class="group flex cursor-grab items-center gap-2.5 rounded-md px-2 py-1.5 text-left text-sm text-ink hover:bg-paper focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-accent active:cursor-grabbing"
                        data-palette-add="{{ $type }}"
                        data-node-category="{{ $category }}"
                        title="{{ $hint }}. Click to add, or drag onto the canvas."
                    >
                        <span class="grid size-7 shrink-0 place-items-center rounded-md" data-palette-icon="{{ $type }}"></span>
                        <span class="font-medium">{{ $label }}</span>
                    </button>
                @endforeach
            </nav>
        @endif

        {{-- Follows the pointer while a node type is dragged out of the toolbox. --}}
        <div class="pipeline-ghost pointer-events-none absolute top-0 left-0 z-30" data-palette-ghost hidden aria-hidden="true"></div>

        {{-- Empty state, shown by the script while the graph has no nodes. --}}
        <div class="pointer-events-none absolute inset-0 z-10 grid place-items-center p-6" data-empty-state hidden>
            <div class="pointer-events-auto relative flex max-w-sm flex-col items-center gap-3 rounded-card border house-edge bg-paper-raised px-8 py-10 text-center shadow-sm">
                <x-brand.mark variant="cat" class="h-14" />
                <h2 class="font-display text-xl tracking-tight">Nothing to chase yet</h2>
                <p class="text-sm text-muted">
                    @if ($editor['can_edit'])
                        Add a source, connect it to a destination, and put filters or a transform in between.
                    @else
                        This pipeline has no nodes yet.
                    @endif
                </p>
                @if ($editor['can_edit'])
                    <div class="mt-1 flex flex-wrap justify-center gap-2">
                        <x-button type="button" :paw="false" data-palette-add="source">Add a source</x-button>
                        <x-button type="button" variant="ghost" :paw="false" data-palette-add="destination">Add a destination</x-button>
                    </div>
                @endif
            </div>
        </div>

        {{-- Viewport controls and history. --}}
        <div class="absolute bottom-4 left-4 z-20 flex items-center gap-1 rounded-lg border border-line bg-paper-raised p-1 shadow-sm" data-zoom-controls>
            <button type="button" class="grid size-8 cursor-pointer place-items-center rounded-md text-muted hover:bg-paper hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-accent" data-zoom-out aria-label="Zoom out">
                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" aria-hidden="true"><path d="M5 12h14" /></svg>
            </button>
            <button type="button" class="min-w-12 cursor-pointer rounded-md px-1 py-1.5 font-mono text-xs text-ink hover:bg-paper focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-accent" data-zoom-reset aria-label="Reset zoom to 100%">
                <span data-zoom-level>100%</span>
            </button>
            <button type="button" class="grid size-8 cursor-pointer place-items-center rounded-md text-muted hover:bg-paper hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-accent" data-zoom-in aria-label="Zoom in">
                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14" /></svg>
            </button>
            <span class="mx-0.5 h-5 w-px bg-line" aria-hidden="true"></span>
            <button type="button" class="grid size-8 cursor-pointer place-items-center rounded-md text-muted hover:bg-paper hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-accent" data-zoom-fit aria-label="Fit to content" title="Fit to content">
                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 9V5a1 1 0 0 1 1-1h4M15 4h4a1 1 0 0 1 1 1v4M20 15v4a1 1 0 0 1-1 1h-4M9 20H5a1 1 0 0 1-1-1v-4" /></svg>
            </button>
            @if ($editor['can_edit'])
                <span class="mx-0.5 h-5 w-px bg-line" aria-hidden="true"></span>
                <button type="button" class="grid size-8 cursor-pointer place-items-center rounded-md text-muted hover:bg-paper hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-accent disabled:cursor-not-allowed disabled:opacity-40" data-undo aria-label="Undo" title="Undo (Ctrl+Z)" disabled>
                    <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 14 4 9l5-5" /><path d="M4 9h10a6 6 0 0 1 0 12h-3" /></svg>
                </button>
                <button type="button" class="grid size-8 cursor-pointer place-items-center rounded-md text-muted hover:bg-paper hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-accent disabled:cursor-not-allowed disabled:opacity-40" data-redo aria-label="Redo" title="Redo (Ctrl+Shift+Z)" disabled>
                    <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 14 5-5-5-5" /><path d="M20 9H10a6 6 0 0 0 0 12h3" /></svg>
                </button>
            @endif
        </div>

        {{-- The inspector: one panel, many contexts (node, connection, test, activity, settings, issues). --}}
        <aside
            id="pipeline-inspector"
            class="pipeline-panel absolute top-4 right-4 bottom-4 z-20 flex w-[min(24rem,calc(100%-2rem))] flex-col overflow-hidden rounded-lg border border-line bg-paper-raised shadow-sm"
            data-inspector
            aria-label="Inspector"
            hidden
        ></aside>

        {{-- Transient notices from the script (save errors, test errors). --}}
        <div class="pointer-events-none absolute inset-x-0 bottom-4 z-30 flex justify-center px-4" data-pipeline-toast hidden>
            <p class="pointer-events-auto rounded-md border border-line bg-ink px-4 py-2 text-sm text-ink-contrast shadow-lg" data-pipeline-toast-text role="status"></p>
        </div>
    </div>
</x-editor-layout>
