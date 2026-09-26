<x-layout title="New destination">
    <x-card heading="New destination" :kicker="$project->name" eared class="max-w-md">
        <p class="text-sm text-ink">
            Choose what kind of destination this is, then fill in the settings and credentials it asks for.
        </p>

        <form class="flex flex-col gap-4" method="GET" action="{{ route('broadcast-destinations.create', $project) }}">
            <x-select
                name="type"
                label="Destination type"
                :options="$types"
                :value="$type"
                hint="Changing the type shows the settings that type needs."
            />

            <x-button variant="ghost" :paw="false">Show settings</x-button>
        </form>

        <x-whisker-divider />

        <form class="flex flex-col gap-4" method="POST" action="{{ route('broadcast-destinations.store', $project) }}">
            @csrf

            <input type="hidden" name="type" value="{{ $type }}">

            <x-input name="name" label="Destination name" autofocus required />

            <x-provider-fields :fields="$fields" />

            <x-credential-fields :fields="$credentialFields" />

            <x-button>Add destination</x-button>
        </form>
    </x-card>
</x-layout>
