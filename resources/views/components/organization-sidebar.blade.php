@props(['organization'])

{{--
    The organization rail: floating under the full-bleed header, inset from
    the left and bottom. It is hidden behind the header's menu button below
    `lg`, a column of icons until `xl`, and icons with labels above it.

    `max-lg:hidden` is the class the menu button toggles, so the rail has one
    hidden state rather than one per breakpoint.
--}}
<aside
    id="organization-sidebar"
    class="fixed z-30 flex flex-col gap-4 rounded-card border border-line bg-paper-raised p-3 max-lg:top-20 max-lg:bottom-3 max-lg:left-3 max-lg:w-56 max-lg:hidden lg:top-20 lg:bottom-3 lg:left-3 lg:w-16 xl:w-56"
>
    <x-sidebar-brand
        :href="route('organizations.show', $organization)"
        :label="$organization->name"
    />

    <nav class="flex flex-col gap-1" aria-label="Organization">
        <x-sidebar-link
            :href="route('organizations.show', $organization)"
            :active="request()->routeIs('organizations.show')"
        >
            <x-slot:icon><x-icons.house class="size-5 shrink-0" /></x-slot:icon>
            Dashboard
        </x-sidebar-link>

        <x-sidebar-link
            :href="route('projects.index', $organization)"
            :active="request()->routeIs('projects.*')"
        >
            <x-slot:icon><x-icons.stack class="size-5 shrink-0" /></x-slot:icon>
            Projects
        </x-sidebar-link>

        <x-sidebar-link
            :href="route('members.index', $organization)"
            :active="request()->routeIs('members.*')"
        >
            <x-slot:icon><x-icons.users class="size-5 shrink-0" /></x-slot:icon>
            Members
        </x-sidebar-link>
    </nav>

    <nav class="mt-auto flex flex-col gap-1 border-t border-line pt-3" aria-label="More">
        @can('update', $organization)
            <x-sidebar-link
                :href="route('organization-settings.edit', $organization)"
                :active="request()->routeIs('organization-settings.*')"
            >
                <x-slot:icon><x-icons.cog class="size-5 shrink-0" /></x-slot:icon>
                Settings
            </x-sidebar-link>
        @endcan

        <x-sidebar-link :href="route('organizations.index')" :active="false">
            <x-slot:icon><x-icons.buildings class="size-5 shrink-0" /></x-slot:icon>
            Switch organization
        </x-sidebar-link>
    </nav>
</aside>
