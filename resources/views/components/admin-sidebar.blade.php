{{--
    Platform admin rail: floating under the full-bleed header. Overview, Users,
    Organizations, Activity, Audit, Horizon, and Pulse are live.
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

        <x-sidebar-link
            :href="route('admin.users.index')"
            :active="request()->routeIs('admin.users.*')"
        >
            <x-slot:icon><x-icons.users class="size-5 shrink-0" /></x-slot:icon>
            Users
        </x-sidebar-link>

        <x-sidebar-link
            :href="route('admin.organizations.index')"
            :active="request()->routeIs('admin.organizations.*')"
        >
            <x-slot:icon><x-icons.buildings class="size-5 shrink-0" /></x-slot:icon>
            Organizations
        </x-sidebar-link>

        <x-sidebar-link
            :href="route('admin.activity')"
            :active="request()->routeIs('admin.activity')"
        >
            <x-slot:icon><x-icons.antenna class="size-5 shrink-0" /></x-slot:icon>
            Activity
        </x-sidebar-link>

        <x-sidebar-link
            :href="route('admin.audit.index')"
            :active="request()->routeIs('admin.audit.*')"
        >
            <x-slot:icon><x-icons.activity class="size-5 shrink-0" /></x-slot:icon>
            Audit
        </x-sidebar-link>

        <x-sidebar-link
            :href="route('horizon.index')"
            :active="request()->routeIs('horizon.*')"
        >
            <x-slot:icon><x-icons.stack class="size-5 shrink-0" /></x-slot:icon>
            Horizon
        </x-sidebar-link>

        <x-sidebar-link
            :href="route('pulse')"
            :active="request()->routeIs('pulse')"
        >
            <x-slot:icon><x-icons.cog class="size-5 shrink-0" /></x-slot:icon>
            Pulse
        </x-sidebar-link>
    </nav>
</aside>
