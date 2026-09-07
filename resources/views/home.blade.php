<x-layout>
    <x-slot:nav>
        <a class="hover:underline" href="{{ route('home') }}">Home</a>
    </x-slot:nav>

    <x-card heading="{{ config('app.name') }}">
        <p class="text-sm text-zinc-700">
            The application shell is in place. Screens added from here extend this layout.
        </p>
    </x-card>

    <x-empty-state heading="Nothing to show yet" message="Projects and broadcasts appear here once those features land.">
        <x-button href="mailto:{{ config('michicast.support_email') }}">Contact support</x-button>
    </x-empty-state>
</x-layout>
