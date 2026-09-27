<x-layout title="New broadcast">
    <x-card heading="New broadcast" kicker="Broadcasts" eared class="max-w-md">
        <p class="text-sm text-ink">
            Name it after the feed it will run, for example a show or a news beat.
        </p>

        <form class="flex flex-col gap-4" method="POST" action="{{ route('broadcasts.store') }}">
            @csrf

            <x-input name="name" label="Broadcast name" autofocus required />

            <x-textarea name="description" label="Description" hint="Optional. Shown to everyone in the organization." />

            <x-button>Create broadcast</x-button>
        </form>
    </x-card>
</x-layout>
