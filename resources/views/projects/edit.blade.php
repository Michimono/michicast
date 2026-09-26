<x-layout title="Edit {{ $project->name }}">
    <x-card heading="Edit broadcast" kicker="Broadcasts" eared class="max-w-md">
        <p class="text-sm text-ink">
            Renaming does not change the slug. That stays {{ $project->slug }}.
        </p>

        <form class="flex flex-col gap-4" method="POST" action="{{ route('flows.update', $project) }}">
            @csrf
            @method('PATCH')

            <x-input name="name" label="Broadcast name" :value="$project->name" autofocus required />

            <x-textarea name="description" label="Description" :value="$project->description" />

            <x-button>Save project</x-button>
        </form>
    </x-card>
</x-layout>
