{{--
    The user-settings rail: floating under the full-bleed header, inset from
    the left and bottom. It is hidden behind the header's menu button below
    `lg`, a column of icons until `xl`, and icons with labels above it.

    `max-lg:hidden` is the class the menu button toggles, so the rail has one
    hidden state rather than one per breakpoint. Links are in-page hashes;
    each target card uses the same heading as the rail item.
--}}
<aside
    id="settings-sidebar"
    class="fixed z-30 flex flex-col gap-4 rounded-card border border-line bg-paper-raised p-3 max-lg:top-20 max-lg:bottom-3 max-lg:left-3 max-lg:w-56 max-lg:hidden lg:top-20 lg:bottom-3 lg:left-3 lg:w-16 xl:w-56"
>
    <x-sidebar-brand
        :href="route('profile.edit')"
        :label="config('app.name')"
    />

    <nav class="flex flex-col gap-1" aria-label="Settings sections">
        <x-sidebar-link href="#profile">
            <x-slot:icon><x-icons.user class="size-5 shrink-0" /></x-slot:icon>
            Profile
        </x-sidebar-link>

        <x-sidebar-link href="#password">
            <x-slot:icon><x-icons.lock class="size-5 shrink-0" /></x-slot:icon>
            Password
        </x-sidebar-link>
    </nav>
</aside>
