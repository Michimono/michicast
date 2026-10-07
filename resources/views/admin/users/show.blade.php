<x-layout :title="'Admin · '.$user->name">
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
                    <span class="inline-flex w-fit rounded-full border border-state-ok/40 px-2 py-0.5 font-mono text-xs tracking-wide text-state-ok uppercase">Active</span>
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
        </dl>

        <p class="text-sm text-muted">
            <a class="text-accent underline hover:no-underline" href="{{ route('admin.users.index') }}">← All users</a>
        </p>
    </x-card>

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
                                · {{ $entry->admin?->name ?? 'Unknown admin' }}
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
