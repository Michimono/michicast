<x-layout :title="'Admin · '.$organization->name">
    <x-card :heading="$organization->name" kicker="Organization" eared>
        <p class="font-mono text-sm text-muted">{{ $organization->slug }}</p>

        <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <div class="flex flex-col gap-1 rounded-md border border-line bg-paper p-4">
                <dt class="font-mono text-[0.6875rem] tracking-[0.12em] text-muted uppercase">Plan</dt>
                <dd class="text-sm text-ink">{{ ucfirst((string) $organization->plan) }}</dd>
            </div>
            <div class="flex flex-col gap-1 rounded-md border border-line bg-paper p-4">
                <dt class="font-mono text-[0.6875rem] tracking-[0.12em] text-muted uppercase">Timezone</dt>
                <dd class="font-mono text-sm text-ink">{{ $organization->timezone }}</dd>
            </div>
            <div class="flex flex-col gap-1 rounded-md border border-line bg-paper p-4">
                <dt class="font-mono text-[0.6875rem] tracking-[0.12em] text-muted uppercase">Status</dt>
                <dd>
                    <span class="inline-flex w-fit rounded-full border border-state-ok/40 px-2 py-0.5 font-mono text-xs tracking-wide text-state-ok uppercase">Active</span>
                </dd>
            </div>
            <div class="flex flex-col gap-1 rounded-md border border-line bg-paper p-4">
                <dt class="font-mono text-[0.6875rem] tracking-[0.12em] text-muted uppercase">Created</dt>
                <dd class="font-mono text-sm text-ink"><x-timestamp :value="$organization->created_at" /></dd>
            </div>
        </dl>

        <p class="text-sm text-muted">
            <a class="text-accent underline hover:no-underline" href="{{ route('admin.organizations.index') }}">← All organizations</a>
        </p>
    </x-card>

    <x-organization-usage-card :organization="$organization" />

    <x-card heading="Members" kicker="People">
        @if ($members->isEmpty())
            <p class="text-sm text-muted">No members.</p>
        @else
            <ul class="flex flex-col divide-y divide-line border-t border-line">
                @foreach ($members as $member)
                    <li class="flex flex-wrap items-center justify-between gap-3 py-3">
                        <div class="flex flex-col gap-0.5">
                            <a
                                class="text-sm font-medium text-accent underline hover:no-underline"
                                href="{{ route('admin.users.show', $member->user) }}"
                            >{{ $member->user->name }}</a>
                            <span class="font-mono text-xs text-muted">{{ $member->user->email }}</span>
                        </div>
                        <span class="text-sm text-muted">{{ $member->role->label() }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-card>

    <x-card heading="Broadcasts" kicker="Projects">
        <p class="text-sm text-muted">Status and health only. Source configuration and destination credentials are never shown here.</p>

        @if ($broadcasts->isEmpty())
            <p class="text-sm text-muted">No broadcasts in this organization.</p>
        @else
            <ul class="flex flex-col divide-y divide-line border-t border-line">
                @foreach ($broadcasts as $row)
                    <li class="flex flex-wrap items-center justify-between gap-3 py-3">
                        <span class="text-sm font-medium text-ink">{{ $row['project']->name }}</span>
                        <div class="flex flex-wrap items-center gap-2">
                            <x-project-status :status="$row['project']->status" />
                            <x-project-health :health="$row['health']" />
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-card>

    <x-card heading="Recent runs" kicker="Activity">
        @if ($recentRuns->isEmpty())
            <p class="text-sm text-muted">No runs yet.</p>
        @else
            <ul class="flex flex-col divide-y divide-line border-t border-line">
                @foreach ($recentRuns as $run)
                    <li class="flex flex-col gap-1 py-3">
                        <div class="flex flex-wrap items-baseline justify-between gap-2">
                            <span class="text-sm text-ink">
                                <span class="font-medium">{{ $run->project?->name ?? 'Unknown broadcast' }}</span>
                                · {{ $run->status->label() }}
                                @if ($run->is_dry)
                                    · dry
                                @endif
                            </span>
                            <span class="font-mono text-xs text-muted"><x-timestamp :value="$run->started_at" /></span>
                        </div>
                        <p class="font-mono text-xs text-muted">
                            found {{ $run->items_found }} · new {{ $run->items_new }} · delivered {{ $run->items_delivered }} · failed {{ $run->items_failed }}
                        </p>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-card>

    <x-card heading="Audit about this organization" kicker="Audit">
        @if ($auditEntries->isEmpty())
            <p class="text-sm text-muted">No admin actions have targeted this organization yet.</p>
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
