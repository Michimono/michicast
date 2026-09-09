<x-layout>
    <x-card :heading="config('app.name')" :kicker="'A '.config('michicast.brand').' product'" eared>
        <p class="text-sm text-ink">
            The application shell is in place. Screens added from here extend this layout.
        </p>
    </x-card>

    <x-empty-state heading="Nothing to chase yet" message="Projects and broadcasts appear here once those features land.">
        <x-button href="mailto:{{ config('michicast.support_email') }}">Contact support</x-button>
    </x-empty-state>
</x-layout>
