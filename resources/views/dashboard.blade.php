<x-layout title="Dashboard">
    <x-card heading="You are signed in">
        <p class="text-sm text-zinc-700">Signed in as {{ auth()->user()?->email }}.</p>

        <p class="text-sm text-zinc-600">
            Times on your screens use your
            <a class="underline hover:no-underline" href="{{ route('profile.edit') }}">display timezone</a>.
            It is now <x-timestamp :value="now()" />.
        </p>
    </x-card>

    <x-empty-state
        heading="Nothing to manage yet"
        message="Organizations, projects and broadcasts appear here as those features land."
    />
</x-layout>
