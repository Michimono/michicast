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
                    @if ($organization->isSuspended())
                        <span class="inline-flex w-fit rounded-full border border-state-fail/40 px-2 py-0.5 font-mono text-xs tracking-wide text-state-fail uppercase">Suspended</span>
                    @else
                        <span class="inline-flex w-fit rounded-full border border-state-ok/40 px-2 py-0.5 font-mono text-xs tracking-wide text-state-ok uppercase">Active</span>
                    @endif
                </dd>
            </div>
            <div class="flex flex-col gap-1 rounded-md border border-line bg-paper p-4">
                <dt class="font-mono text-[0.6875rem] tracking-[0.12em] text-muted uppercase">Created</dt>
                <dd class="font-mono text-sm text-ink"><x-timestamp :value="$organization->created_at" /></dd>
            </div>
            @if ($organization->isSuspended())
                <div class="flex flex-col gap-1 rounded-md border border-line bg-paper p-4 sm:col-span-2 lg:col-span-3">
                    <dt class="font-mono text-[0.6875rem] tracking-[0.12em] text-muted uppercase">Suspension reason</dt>
                    <dd class="text-sm text-ink">{{ $organization->suspension_reason }}</dd>
                    <dd class="font-mono text-xs text-muted">
                        Suspended
                        @if ($organization->suspended_at)
                            <x-timestamp :value="$organization->suspended_at" />
                        @endif
                        @if ($organization->suspendedBy)
                            by {{ $organization->suspendedBy->name }}
                        @endif
                    </dd>
                </div>
            @endif
        </dl>

        <p class="text-sm text-muted">
            <a class="text-accent underline hover:no-underline" href="{{ route('admin.organizations.index') }}">← All organizations</a>
        </p>
    </x-card>

    @if ($organization->isSuspended())
        <x-card heading="Reinstate organization" kicker="Moderation">
            <p class="text-sm text-muted">
                Restores writes and scheduled broadcasts. Active project schedules are recalculated from now so a long suspension does not cause a catch-up burst.
                Broadcasts themselves are left as they were; nothing is paused or archived by suspension.
            </p>

            <form
                class="flex flex-col gap-4"
                method="POST"
                action="{{ route('admin.organizations.suspend.destroy', $organization) }}"
                onsubmit="return confirm('Reinstate {{ $organization->name }}? Writes and schedules will resume.')"
            >
                @csrf
                @method('DELETE')

                <x-button variant="ghost">Reinstate organization</x-button>
            </form>
        </x-card>
    @else
        <x-card heading="Suspend organization" kicker="Moderation">
            <p class="text-sm text-muted">
                Stops every write, run, invitation and scheduled broadcast for this organization without deleting data.
                Members can still sign in and read.
            </p>

            <form
                class="flex flex-col gap-4"
                method="POST"
                action="{{ route('admin.organizations.suspend.store', $organization) }}"
                onsubmit="return confirm('Suspend {{ $organization->name }}? Writes and broadcasts will stop.')"
            >
                @csrf

                <x-textarea
                    name="reason"
                    label="Reason"
                    rows="3"
                    required
                    hint="Stored on the organization and in the audit log. Shown to platform admins on the member banner."
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
                    <span>I confirm this suspends writes and broadcasts until an admin reinstates the organization.</span>
                </label>
                @error('confirmation')
                    <p class="text-sm text-state-fail">{{ $message }}</p>
                @enderror

                <x-button>Suspend organization</x-button>
            </form>
        </x-card>
    @endif

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