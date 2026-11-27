<x-layout title="Organizations">
    <x-page-header
        kicker="Organizations"
        heading="Your organizations"
        lead="Each organization keeps its own projects, members and schedules. Open one to work in it."
    >
        <x-slot:actions>
            <x-button href="{{ route('organizations.create') }}">New organization</x-button>
        </x-slot:actions>
    </x-page-header>

    <x-card eared>
        <ul class="flex flex-col divide-y divide-line">
            @foreach ($memberships as $membership)
                <li class="flex flex-wrap items-center justify-between gap-4 py-4">
                    <div class="flex flex-col gap-0.5">
                        <a
                            class="text-sm text-accent underline hover:no-underline"
                            href="{{ route('organizations.show', $membership->organization) }}"
                        >{{ $membership->organization->name }}</a>

                        <span class="font-mono text-xs text-muted">{{ $membership->organization->slug }}</span>
                    </div>

                    <span class="text-sm text-muted">{{ $membership->role->label() }}</span>
                </li>
            @endforeach
        </ul>
    </x-card>
</x-layout>
