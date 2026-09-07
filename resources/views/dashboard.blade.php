<x-layout title="Dashboard">
    <x-card heading="You are signed in">
        <p class="text-sm text-zinc-700">Signed in as {{ auth()->user()?->email }}.</p>
    </x-card>

    <x-empty-state
        heading="Nothing to manage yet"
        message="Organizations, projects and broadcasts appear here as those features land."
    />
</x-layout>
