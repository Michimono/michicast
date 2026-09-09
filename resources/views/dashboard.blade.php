<x-layout title="Dashboard">
    <x-card heading="You are signed in" kicker="Dashboard" eared>
        <p class="text-sm text-ink">Signed in as {{ auth()->user()?->email }}.</p>

        <p class="text-sm text-muted">
            Times on your screens use your
            <a class="text-accent underline hover:no-underline" href="{{ route('profile.edit') }}">display timezone</a>.
            It is now <x-timestamp :value="now()" />.
        </p>
    </x-card>

    <x-empty-state
        heading="Nothing to manage yet"
        message="Organizations, projects and broadcasts appear here as those features land."
    />
</x-layout>
