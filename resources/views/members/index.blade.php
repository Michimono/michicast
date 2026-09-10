<x-layout title="Members">
    <x-card :heading="$organization->name" kicker="Members" eared>
        <p class="text-sm text-muted">Who belongs to this organization, and what each of them may do.</p>

        <ul class="flex flex-col divide-y divide-line border-t border-line">
            @foreach ($members as $member)
                <li class="flex flex-wrap items-center justify-between gap-4 py-4">
                    <div class="flex flex-col gap-0.5">
                        <span class="text-sm">{{ $member->user->name }}</span>
                        <span class="font-mono text-xs text-muted">{{ $member->user->email }}</span>
                    </div>

                    <div class="flex items-center gap-4">
                        <span class="text-sm text-muted">{{ $member->role->label() }}</span>

                        @can('update', $member)
                            <a
                                class="text-sm text-accent underline hover:no-underline"
                                href="{{ route('members.role.edit', $member) }}"
                            >Change role</a>
                        @endcan
                    </div>
                </li>
            @endforeach
        </ul>
    </x-card>
</x-layout>
