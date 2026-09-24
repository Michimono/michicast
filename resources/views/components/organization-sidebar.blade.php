@props(['organization', 'project' => null, 'organizations' => null, 'projects' => null])

@php
    $organizations ??= auth()->user()?->organizations()->orderBy('name')->get() ?? collect();
    $projects ??= $organization->projects()->notArchived()->orderBy('name')->get();
    $inProject = $project instanceof \App\Models\Project;
    $projectsActive = ! $inProject && request()->routeIs('organizations.show', 'projects.index', 'projects.create');
@endphp

{{--
    Contextual two-level rail: organization (Projects / Members / Settings),
    then project (Overview / Sources / Destinations / Activity)
    when a project is in scope. Mobile uses the same hierarchy via the header menu.
--}}
<aside
    id="organization-sidebar"
    class="fixed z-30 flex flex-col gap-4 overflow-y-auto rounded-card border border-line bg-paper-raised p-3 max-lg:top-20 max-lg:bottom-3 max-lg:left-3 max-lg:w-56 max-lg:hidden lg:top-20 lg:bottom-3 lg:left-3 lg:w-16 xl:w-56"
>
    <div class="flex flex-col gap-2">
        <details class="relative z-20" data-sidebar-menu>
            <summary class="flex cursor-pointer list-none items-center gap-2 rounded-md px-1 py-2 text-left max-lg:px-2 lg:justify-center xl:justify-start xl:px-2 [&::-webkit-details-marker]:hidden">
                <x-icons.info class="hidden shrink-0 lg:block xl:hidden" />
                <span class="min-w-0 flex-1 truncate font-display text-base tracking-tight max-lg:block lg:hidden xl:block">{{ $organization->name }}</span>
                <span class="font-mono text-[0.625rem] tracking-wide text-muted uppercase max-lg:inline lg:hidden xl:inline">Org</span>
            </summary>
            <div class="absolute left-0 right-0 z-40 mt-1 max-h-64 overflow-y-auto rounded-md border border-line bg-paper-raised py-1 shadow-md xl:left-0">
                @foreach ($organizations as $candidate)
                    <a
                        href="{{ route('organizations.show', $candidate) }}"
                        @class([
                            'block truncate px-3 py-2 text-sm hover:bg-paper',
                            'bg-paper font-medium text-ink' => $candidate->is($organization),
                            'text-ink' => ! $candidate->is($organization),
                        ])
                        @if ($candidate->is($organization)) aria-current="true" @endif
                    >{{ $candidate->name }}</a>
                @endforeach
                <a href="{{ route('organizations.index') }}" class="block border-t border-line px-3 py-2 text-sm text-muted hover:bg-paper hover:text-ink">
                    All organizations
                </a>
            </div>
        </details>
    </div>

    <nav class="flex flex-col gap-1" aria-label="Organization">
        <x-sidebar-link
            :href="route('organizations.show', $organization)"
            :active="$projectsActive"
        >
            <x-slot:icon><x-icons.stack class="size-5 shrink-0" /></x-slot:icon>
            Projects
        </x-sidebar-link>

        <x-sidebar-link
            :href="route('members.index', $organization)"
            :active="request()->routeIs('members.*', 'member-roles.*', 'invitations.*')"
        >
            <x-slot:icon><x-icons.users class="size-5 shrink-0" /></x-slot:icon>
            Members
        </x-sidebar-link>

        @can('update', $organization)
            <x-sidebar-link
                :href="route('organization-settings.edit', $organization)"
                :active="request()->routeIs('organization-settings.*')"
            >
                <x-slot:icon><x-icons.cog class="size-5 shrink-0" /></x-slot:icon>
                Settings
            </x-sidebar-link>
        @endcan
    </nav>

    @if ($inProject)
        <div class="flex flex-col gap-2 border-t border-line pt-3">
            <details class="relative z-20" data-sidebar-menu>
                <summary class="flex cursor-pointer list-none items-center gap-2 rounded-md px-1 py-2 text-left max-lg:px-2 lg:justify-center xl:justify-start xl:px-2 [&::-webkit-details-marker]:hidden">
                    <x-icons.info class="hidden shrink-0 lg:block xl:hidden" />
                    <span class="min-w-0 flex-1 truncate font-display text-base tracking-tight max-lg:block lg:hidden xl:block">{{ $project->name }}</span>
                    <span class="font-mono text-[0.625rem] tracking-wide text-muted uppercase max-lg:inline lg:hidden xl:inline">Project</span>
                </summary>
                <div class="absolute left-0 right-0 z-40 mt-1 max-h-64 overflow-y-auto rounded-md border border-line bg-paper-raised py-1 shadow-md">
                    @forelse ($projects as $candidate)
                        <a
                            href="{{ route('projects.show', $candidate) }}"
                            @class([
                                'block truncate px-3 py-2 text-sm hover:bg-paper',
                                'bg-paper font-medium text-ink' => $candidate->is($project),
                                'text-ink' => ! $candidate->is($project),
                            ])
                            @if ($candidate->is($project)) aria-current="true" @endif
                        >{{ $candidate->name }}</a>
                    @empty
                        <p class="px-3 py-2 text-sm text-muted">No projects yet.</p>
                    @endforelse
                </div>
            </details>

            <nav class="flex flex-col gap-1" aria-label="Project">
                <x-sidebar-link
                    :href="route('projects.show', $project)"
                    :active="request()->routeIs('projects.show')"
                >
                    <x-slot:icon><x-icons.overview class="size-5 shrink-0" /></x-slot:icon>
                    Overview
                </x-sidebar-link>


                <x-sidebar-link
                    :href="route('project-sources.index', $project)"
                    :active="request()->routeIs('project-sources.*', 'sources.*', 'source-connection-tests.*', 'enabled-sources.*', 'archived-sources.*')"
                >
                    <x-slot:icon><x-icons.source class="size-5 shrink-0" /></x-slot:icon>
                    Sources
                </x-sidebar-link>

                <x-sidebar-link
                    :href="route('project-destinations.index', $project)"
                    :active="request()->routeIs('project-destinations.*', 'destinations.*', 'destination-connection-tests.*', 'enabled-destinations.*', 'archived-destinations.*')"
                >
                    <x-slot:icon><x-icons.destination class="size-5 shrink-0" /></x-slot:icon>
                    Destinations
                </x-sidebar-link>

                <x-sidebar-link
                    :href="route('project-runs.index', $project)"
                    :active="request()->routeIs('project-runs.*')"
                >
                    <x-slot:icon><x-icons.activity class="size-5 shrink-0" /></x-slot:icon>
                    Activity
                </x-sidebar-link>
            </nav>
        </div>
    @endif
</aside>