<x-layout title="New destination">
    <x-card heading="New destination" :kicker="$project->name" eared class="max-w-md">
        <p class="text-sm text-ink">
            Choose what kind of destination this is, then fill in the settings and credentials it asks for.
        </p>

        {{--
            Without JS, "Show settings" reloads the page for the chosen type. With JS
            (app.js, data-destination-type-switch) the type select swaps the field
            groups below instantly and the button is hidden.
        --}}
        <div class="flex flex-col gap-4" data-destination-type-switch>
            <form class="flex flex-col gap-4" method="GET" action="{{ route('broadcast-destinations.create', $project) }}">
                <x-select
                    name="type"
                    label="Destination type"
                    :options="$types"
                    :value="$type"
                    hint="Changing the type shows the settings that type needs."
                    data-destination-type-select
                />

                <x-button variant="ghost" :paw="false" data-destination-type-reload>Show settings</x-button>
            </form>

            <x-whisker-divider />

            <form class="flex flex-col gap-4" method="POST" action="{{ route('broadcast-destinations.store', $project) }}">
                @csrf

                <input type="hidden" name="type" value="{{ $type }}" data-destination-type-input>

                <x-input name="name" label="Destination name" autofocus required />

                {{-- One group per type; only the chosen one is enabled, so only its fields post. --}}
                @foreach ($typeFields as $option => $group)
                    <fieldset
                        class="m-0 flex min-w-0 flex-col gap-4 border-0 p-0"
                        data-destination-type-fields="{{ $option }}"
                        @if ($option !== $type) hidden disabled @endif
                    >
                        <legend class="sr-only">{{ $types[$option] ?? $option }} settings</legend>

                        <x-provider-fields :fields="$group['fields']" />

                        <x-credential-fields :fields="$group['credentialFields']" />
                    </fieldset>
                @endforeach

                <x-button>Add destination</x-button>
            </form>
        </div>
    </x-card>
</x-layout>