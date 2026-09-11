<x-layout title="Organizations">
    <x-card heading="Your organizations" kicker="Organizations" eared>
        <p class="text-sm text-muted">
            Each organization keeps its own projects, members and schedules. Open one to work in it.
        </p>

        <p>
            <x-button href="{{ route('organizations.create') }}">New organization</x-button>
        </p>

        <ul class="flex flex-col divide-y divide-line border-t border-line">
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
