{{--
    Platform admin rail: floating under the full-bleed header. Overview and Audit
    are live; Users, Organizations, Horizon and Pulse stay as placeholders until
    their issues land.
--}}
<aside
    id="admin-sidebar"
    class="fixed z-30 flex flex-col gap-4 rounded-card border border-line bg-paper-raised p-3 max-lg:top-20 max-lg:bottom-3 max-lg:left-3 max-lg:w-56 max-lg:hidden lg:top-20 lg:bottom-3 lg:left-3 lg:w-16 xl:w-56"
>
    <x-sidebar-context
        :href="route('admin.overview')"
        label="Admin"
    />

    <nav class="flex flex-col gap-1" aria-label="Admin">
        <x-sidebar-link
            :href="route('admin.overview')"
            :active="request()->routeIs('admin.overview')"
        >
            <x-slot:icon><x-icons.overview class="size-5 shrink-0" /></x-slot:icon>
            Overview
        </x-sidebar-link>

        <span class="group relative flex items-center gap-3 rounded-md px-3 py-2 text-muted lg:justify-center xl:justify-start" aria-disabled="true">
            <x-icons.users class="size-5 shrink-0" />
            <span class="text-sm lg:pointer-events-none lg:absolute lg:left-full lg:z-10 lg:ml-2 lg:w-max lg:rounded-md lg:border lg:border-line lg:bg-paper-raised lg:px-2 lg:py-1 lg:text-ink lg:opacity-0 lg:transition-opacity lg:group-hover:opacity-100 xl:pointer-events-auto xl:static xl:ml-0 xl:rounded-none xl:border-0 xl:bg-transparent xl:px-0 xl:py-0 xl:opacity-100">Users</span>
        </span>

        <span class="group relative flex items-center gap-3 rounded-md px-3 py-2 text-muted lg:justify-center xl:justify-start" aria-disabled="true">
            <x-icons.buildings class="size-5 shrink-0" />
            <span class="text-sm lg:pointer-events-none lg:absolute lg:left-full lg:z-10 lg:ml-2 lg:w-max lg:rounded-md lg:border lg:border-line lg:bg-paper-raised lg:px-2 lg:py-1 lg:text-ink lg:opacity-0 lg:transition-opacity lg:group-hover:opacity-100 xl:pointer-events-auto xl:static xl:ml-0 xl:rounded-none xl:border-0 xl:bg-transparent xl:px-0 xl:py-0 xl:opacity-100">Organizations</span>
        </span>

        <x-sidebar-link
            :href="route('admin.audit.index')"
            :active="request()->routeIs('admin.audit.*')"
        >
            <x-slot:icon><x-icons.activity class="size-5 shrink-0" /></x-slot:icon>
            Audit
        </x-sidebar-link>

        <span class="group relative flex items-center gap-3 rounded-md px-3 py-2 text-muted lg:justify-center xl:justify-start" aria-disabled="true">
            <x-icons.stack class="size-5 shrink-0" />
            <span class="text-sm lg:pointer-events-none lg:absolute lg:left-full lg:z-10 lg:ml-2 lg:w-max lg:rounded-md lg:border lg:border-line lg:bg-paper-raised lg:px-2 lg:py-1 lg:text-ink lg:opacity-0 lg:transition-opacity lg:group-hover:opacity-100 xl:pointer-events-auto xl:static xl:ml-0 xl:rounded-none xl:border-0 xl:bg-transparent xl:px-0 xl:py-0 xl:opacity-100">Horizon</span>
        </span>

        <span class="group relative flex items-center gap-3 rounded-md px-3 py-2 text-muted lg:justify-center xl:justify-start" aria-disabled="true">
            <x-icons.cog class="size-5 shrink-0" />
            <span class="text-sm lg:pointer-events-none lg:absolute lg:left-full lg:z-10 lg:ml-2 lg:w-max lg:rounded-md lg:border lg:border-line lg:bg-paper-raised lg:px-2 lg:py-1 lg:text-ink lg:opacity-0 lg:transition-opacity lg:group-hover:opacity-100 xl:pointer-events-auto xl:static xl:ml-0 xl:rounded-none xl:border-0 xl:bg-transparent xl:px-0 xl:py-0 xl:opacity-100">Pulse</span>
        </span>
    </nav>
</aside>