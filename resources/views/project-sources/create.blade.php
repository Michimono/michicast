<x-layout title="New source">
    <x-card heading="New source" :kicker="$project->name" eared class="max-w-md">
        <p class="text-sm text-ink">
            Choose what kind of source this is, then fill in the settings it asks for.
        </p>

        <form class="flex flex-col gap-4" method="GET" action="{{ route('project-sources.create', $project) }}">
            <x-select
                name="type"
                label="Source type"
                :options="$types"
                :value="$type"
                hint="Changing the type shows the settings that type needs."
            />

            <x-button variant="ghost" :paw="false">Show settings</x-button>
        </form>

        <x-whisker-divider />

        <form class="flex flex-col gap-4" method="POST" action="{{ route('project-sources.store', $project) }}">
            @csrf

            <input type="hidden" name="type" value="{{ $type }}">

            <x-input name="name" label="Source name" autofocus required />

            <x-provider-fields :fields="$fields" />

            <x-button>Add source</x-button>
        </form>
    </x-card>
</x-layout>
