<x-layout title="New project">
    <x-card heading="New project" kicker="Projects" eared class="max-w-md">
        <p class="text-sm text-ink">
            Name it after the pipeline it will run, for example a show or a news beat.
        </p>

        <form class="flex flex-col gap-4" method="POST" action="{{ route('projects.store') }}">
            @csrf

            <x-input name="name" label="Project name" autofocus required />

            <x-textarea name="description" label="Description" hint="Optional. Shown to everyone in the organization." />

            <x-button>Create project</x-button>
        </form>
    </x-card>
</x-layout>
