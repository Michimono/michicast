<x-layout :title="'Filters · '.$broadcast->name">
    <x-card heading="Filters" :kicker="$broadcast->name" eared class="max-w-lg">
        <p class="text-sm text-ink">
            Every rule must match for an item to publish. Leave the list empty to pass everything.
        </p>

        <form
            class="flex flex-col gap-4"
            method="POST"
            action="{{ route('broadcast-filters.update', $broadcast) }}"
            data-filter-form
        >
            @csrf
            @method('PATCH')

            <div class="flex flex-col gap-3" data-filter-rules>
                @forelse ($rules as $index => $rule)
                    <x-broadcast-filter-rule
                        :index="$index"
                        :rule="$rule"
                        :types="$types"
                        :fields="$fields"
                    />
                @empty
                @endforelse
            </div>

            <template data-filter-rule-template>
                <x-broadcast-filter-rule
                    :index="'__INDEX__'"
                    :rule="[]"
                    :types="$types"
                    :fields="$fields"
                />
            </template>

            <div class="flex flex-wrap items-center gap-2">
                <x-button type="button" variant="ghost" :paw="false" data-filter-add>Add rule</x-button>
                <x-button>Save filters</x-button>
            </div>
        </form>

        <p>
            <x-button href="{{ route('broadcasts.edit', $broadcast) }}" variant="ghost" :paw="false">Back to broadcast</x-button>
        </p>
    </x-card>
</x-layout>
