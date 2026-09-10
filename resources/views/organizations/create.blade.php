<x-layout title="Create organization">
    <x-card heading="Create your organization" kicker="Organizations" eared class="max-w-md">
        <p class="text-sm text-ink">
            Projects, sources and destinations all live inside an organization. Start with one; you can create more later.
        </p>

        <form class="flex flex-col gap-4" method="POST" action="{{ route('organizations.store') }}">
            @csrf

            <x-input name="name" label="Organization name" autocomplete="organization" autofocus required />

            <x-select
                name="timezone"
                label="Organization timezone"
                :value="$timezone"
                :options="$timezones"
                hint="Schedules run on this timezone, whoever is looking at them."
            />

            <x-button>Create organization</x-button>
        </form>
    </x-card>
</x-layout>
