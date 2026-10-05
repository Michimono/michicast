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

                        @can('delete', $member)
                            @unless ($member->isLastOwner())
                                <form
                                    method="POST"
                                    action="{{ route('members.destroy', $member) }}"
                                    onsubmit="return confirm('{{ $member->user->is(auth()->user()) ? 'Leave this organization? You will lose access to it.' : 'Remove this member from the organization?' }}')"
                                >
                                    @csrf
                                    @method('DELETE')
                                    <input type="hidden" name="confirmation" value="1">

                                    <button class="text-sm text-accent underline hover:no-underline" type="submit">
                                        {{ $member->user->is(auth()->user()) ? 'Leave' : 'Remove' }}
                                    </button>
                                </form>
                            @endunless
                        @endcan
                    </div>
                </li>
            @endforeach
        </ul>
    </x-card>

    @can('create', [App\Models\OrganizationInvitation::class, $organization])
        <x-card heading="Invite someone" kicker="Invitations" class="max-w-md">
            <p class="text-sm text-ink">They get an email with a link. The invitation expires in {{ App\Models\OrganizationInvitation::EXPIRES_AFTER_DAYS }} days.</p>

            <form class="flex flex-col gap-4" method="POST" action="{{ route('invitations.store') }}">
                @csrf

                <x-input name="email" label="Email address" type="email" autocomplete="email" required />

                <x-select name="role" label="Role" value="viewer" :options="$roles" />

                <x-button>Send invitation</x-button>
            </form>
        </x-card>

        @if ($invitations->isNotEmpty())
            <x-card heading="Pending invitations">
                <ul class="flex flex-col divide-y divide-line border-t border-line">
                    @foreach ($invitations as $invitation)
                        <li class="flex flex-wrap items-center justify-between gap-4 py-4">
                            <div class="flex flex-col gap-0.5">
                                <span class="font-mono text-sm">{{ $invitation->email }}</span>
                                <span class="text-sm text-muted">{{ $invitation->role->label() }}</span>
                            </div>

                            <form method="POST" action="{{ route('invitations.destroy', $invitation) }}">
                                @csrf
                                @method('DELETE')

                                <button class="text-sm text-accent underline hover:no-underline" type="submit">
                                    Cancel
                                </button>
                            </form>
                        </li>
                    @endforeach
                </ul>
            </x-card>
        @endif
    @endcan
</x-layout>
