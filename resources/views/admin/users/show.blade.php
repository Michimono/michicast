<x-layout :title="'Admin — '.$user->name">
    <x-card :heading="$user->name" kicker="User" eared>
        <p class="font-mono text-sm text-muted">{{ $user->email }}</p>

        <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <div class="flex flex-col gap-1 rounded-md border border-line bg-paper p-4">
                <dt class="font-mono text-[0.6875rem] tracking-[0.12em] text-muted uppercase">Created</dt>
                <dd class="font-mono text-sm text-ink"><x-timestamp :value="$user->created_at" /></dd>
            </div>
            <div class="flex flex-col gap-1 rounded-md border border-line bg-paper p-4">
                <dt class="font-mono text-[0.6875rem] tracking-[0.12em] text-muted uppercase">Last seen</dt>
                <dd class="font-mono text-sm text-ink">
                    @if ($lastSeen)
                        <x-timestamp :value="$lastSeen" />
                    @else
                        Never
                    @endif
                </dd>
            </div>
            <div class="flex flex-col gap-1 rounded-md border border-line bg-paper p-4">
                <dt class="font-mono text-[0.6875rem] tracking-[0.12em] text-muted uppercase">Status</dt>
                <dd>
                    @if ($user->isBanned())
                        <span class="inline-flex w-fit rounded-full border border-state-fail/40 px-2 py-0.5 font-mono text-xs tracking-wide text-state-fail uppercase">Banned</span>
                    @else
                        <span class="inline-flex w-fit rounded-full border border-state-ok/40 px-2 py-0.5 font-mono text-xs tracking-wide text-state-ok uppercase">Active</span>
                    @endif
                    @if ($user->isPlatformAdmin())
                        <span class="ml-2 inline-flex w-fit rounded-full border border-line px-2 py-0.5 font-mono text-xs tracking-wide text-muted uppercase">Admin</span>
                    @endif
                </dd>
            </div>
            <div class="flex flex-col gap-1 rounded-md border border-line bg-paper p-4">
                <dt class="font-mono text-[0.6875rem] tracking-[0.12em] text-muted uppercase">Timezone</dt>
                <dd class="font-mono text-sm text-ink">{{ $user->timezone }}</dd>
            </div>
            <div class="flex flex-col gap-1 rounded-md border border-line bg-paper p-4">
                <dt class="font-mono text-[0.6875rem] tracking-[0.12em] text-muted uppercase">Two-factor</dt>
                <dd class="text-sm text-ink">{{ $user->hasTwoFactorEnabled() ? 'Enabled' : 'Off' }}</dd>
            </div>
            @if ($user->isBanned())
                <div class="flex flex-col gap-1 rounded-md border border-line bg-paper p-4 sm:col-span-2 lg:col-span-3">
                    <dt class="font-mono text-[0.6875rem] tracking-[0.12em] text-muted uppercase">Ban reason</dt>
                    <dd class="text-sm text-ink">{{ $user->ban_reason }}</dd>
                    <dd class="font-mono text-xs text-muted">
                        Banned
                        @if ($user->banned_at)
                            <x-timestamp :value="$user->banned_at" />
                        @endif
                        @if ($user->bannedBy)
                            by {{ $user->bannedBy->name }}
                        @endif
                    </dd>
                </div>
            @endif
        </dl>

        <p class="text-sm text-muted">
            <a class="text-accent underline hover:no-underline" href="{{ route('admin.users.index') }}">← All users</a>
        </p>
    </x-card>

    @unless ($user->is(auth()->user()) || $user->isPlatformAdmin())
        @if ($user->isBanned())
            <x-card heading="Unban user" kicker="Moderation">
                <p class="text-sm text-muted">
                    Clears the ban so they can sign in again. Existing sessions are not restored; they must log in fresh.
                </p>

                <form
                    class="flex flex-col gap-4"
                    method="POST"
                    action="{{ route('admin.users.ban.destroy', $user) }}"
                    onsubmit="return confirm('Unban {{ $user->name }}? They will be able to sign in again.')"
                >
                    @csrf
                    @method('DELETE')

                    <x-button variant="ghost">Unban user</x-button>
                </form>
            </x-card>
        @else
            <x-card heading="Ban user" kicker="Moderation">
                <p class="text-sm text-muted">
                    Signs them out everywhere, blocks login and password reset, and prevents invitation acceptance.
                    This does not delete their account, organizations, or broadcasts.
                </p>

                <form
                    class="flex flex-col gap-4"
                    method="POST"
                    action="{{ route('admin.users.ban.store', $user) }}"
                    onsubmit="return confirm('Ban {{ $user->name }}? They will be signed out everywhere.')"
                >
                    @csrf

                    <x-textarea
                        name="reason"
                        label="Reason"
                        rows="3"
                        required
                        hint="Stored on the user and in the audit log. Not shown to the banned user."
                    />

                    <label class="flex items-start gap-3 text-sm text-ink">
                        <input
                            class="mt-1 size-4 rounded border-line-strong text-accent focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
                            type="checkbox"
                            name="confirmation"
                            value="1"
                            required
                            @checked(old('confirmation'))
                        >
                        <span>I confirm this ban signs them out everywhere and blocks sign-in until an admin unbans them.</span>
                    </label>
                    @error('confirmation')
                        <p class="text-sm text-state-fail">{{ $message }}</p>
                    @enderror

                    <label class="flex items-start gap-3 text-sm text-ink">
                        <input
                            class="mt-1 size-4 rounded border-line-strong text-accent focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
                            type="checkbox"
                            name="suspend_sole_owned_organizations"
                            value="1"
                            @checked(old('suspend_sole_owned_organizations'))
                        >
                        <span>
                            Also suspend every organization where they are the only owner.
                            <span class="text-muted">Those organizations stop writing and broadcasting until an admin reinstates them.</span>
                        </span>
                    </label>

                    <x-button>Ban user</x-button>
                </form>
            </x-card>
        @endif
    @else
        <x-card heading="Ban user" kicker="Moderation">
            @if ($user->is(auth()->user()))
                <p class="text-sm text-muted">You cannot ban your own account.</p>
            @else
                <p class="text-sm text-muted">Platform admins cannot be banned. Revoke admin access from the CLI first.</p>
            @endif
        </x-card>
    @endunless

    <x-card heading="Memberships" kicker="Organizations">
        @if ($memberships->isEmpty())
            <p class="text-sm text-muted">This user does not belong to any organization.</p>
        @else
            <ul class="flex flex-col divide-y divide-line border-t border-line">
                @foreach ($memberships as $membership)
                    <li class="flex flex-wrap items-center justify-between gap-3 py-3">
                        <a
                            class="text-sm font-medium text-accent underline hover:no-underline"
                            href="{{ route('admin.organizations.show', $membership->organization) }}"
                        >{{ $membership->organization->name }}</a>
                        <span class="text-sm text-muted">{{ $membership->role->label() }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-card>

    <x-card heading="Owned organizations" kicker="Owner">
        @if ($ownedOrganizations->isEmpty())
            <p class="text-sm text-muted">This user does not own any organization.</p>
        @else
            <ul class="flex flex-col divide-y divide-line border-t border-line">
                @foreach ($ownedOrganizations as $owned)
                    <li class="py-3">
                        <a
                            class="text-sm font-medium text-accent underline hover:no-underline"
                            href="{{ route('admin.organizations.show', $owned) }}"
                        >{{ $owned->name }}</a>
                        <p class="font-mono text-xs text-muted">{{ $owned->slug }}</p>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-card>

    <x-card heading="Recent sessions" kicker="Sessions">
        <p class="text-sm text-muted">IP and user agent only. Session payloads are never shown.</p>

        @if ($sessions->isEmpty())
            <p class="text-sm text-muted">No sessions recorded.</p>
        @else
            <ul class="flex flex-col divide-y divide-line border-t border-line">
                @foreach ($sessions as $session)
                    <li class="flex flex-col gap-1 py-3">
                        <div class="flex flex-wrap items-baseline justify-between gap-2">
                            <span class="font-mono text-sm text-ink">{{ $session->ip_address ?: 'Unknown IP' }}</span>
                            <span class="font-mono text-xs text-muted"><x-timestamp :value="$session->last_activity" /></span>
                        </div>
                        <p class="break-all font-mono text-xs text-muted">{{ $session->user_agent ?: 'Unknown agent' }}</p>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-card>

    <x-card heading="Audit about this user" kicker="Audit">
        @if ($auditEntries->isEmpty())
            <p class="text-sm text-muted">No admin actions have targeted this user yet.</p>
        @else
            <ul class="flex flex-col divide-y divide-line border-t border-line">
                @foreach ($auditEntries as $entry)
                    <li class="flex flex-col gap-1 py-3">
                        <div class="flex flex-wrap items-baseline justify-between gap-2">
                            <span class="text-sm text-ink">
                                <span class="font-medium">{{ $entry->action->label() }}</span>
                                — {{ $entry->admin?->name ?? 'Unknown admin' }}
                            </span>
                            <span class="font-mono text-xs text-muted"><x-timestamp :value="$entry->created_at" /></span>
                        </div>
                        @if ($entry->reason)
                            <p class="text-sm text-muted">Reason: {{ $entry->reason }}</p>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </x-card>
</x-layout>
