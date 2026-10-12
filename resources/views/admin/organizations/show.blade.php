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

        <x-card id="quotas" heading="Quotas" kicker="Plan & limits" eared class="scroll-mt-8">
        <p class="text-sm text-muted">
            Plan limits come from config. Overrides live in the database and win when present (null = unlimited).
            Rate usage counts per UTC calendar period (day, Monday-week or month; plan default unless set below). Changing a plan, override or period takes effect immediately for the next run.
        </p>

        <form
            class="flex flex-col gap-4 rounded-md border border-line bg-paper p-4"
            method="POST"
            action="{{ route('admin.organizations.plan.update', $organization) }}"
        >
            @csrf
            @method('PATCH')

            <x-select
                name="plan"
                label="Plan"
                :options="$planOptions"
                :value="$organization->plan ?? $resolvedPlan"
                hint="Configured plans only. Plan definitions stay in config."
                required
            />

            <x-textarea
                name="reason"
                label="Reason"
                rows="2"
                required
                hint="Required. Stored in the audit log with before and after plan values."
            />

            <x-button>Change plan</x-button>
        </form>

        <div class="flex flex-col gap-6">
            @foreach ($quotaRows as $row)
                @php
                    $metric = $row['metric'];
                    $formatLimit = static function (?int $limit): string {
                        return $limit === null ? 'Unlimited' : number_format($limit);
                    };
                @endphp

                <div class="flex flex-col gap-4 rounded-md border border-line bg-paper p-4">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <h3 class="text-sm font-medium text-ink">{{ $row['label'] }}</h3>
                        @if ($row['is_rate'])
                            <span class="font-mono text-xs text-muted uppercase">{{ $row['period']->label() }}@unless ($row['has_period_setting']) <span class="normal-case">(plan)</span>@endunless</span>
                        @else
                            <span class="font-mono text-xs text-muted uppercase">Stock</span>
                        @endif
                    </div>

                    <dl class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <div class="flex flex-col gap-1">
                            <dt class="font-mono text-[0.6875rem] tracking-[0.12em] text-muted uppercase">Plan limit</dt>
                            <dd class="font-mono text-sm text-ink">{{ $formatLimit($row['plan_limit']) }}</dd>
                        </div>
                        <div class="flex flex-col gap-1">
                            <dt class="font-mono text-[0.6875rem] tracking-[0.12em] text-muted uppercase">Override</dt>
                            <dd class="font-mono text-sm text-ink">
                                @if ($row['has_override'])
                                    {{ $formatLimit($row['override_limit']) }}
                                    @if ($row['override_note'])
                                        <span class="block text-xs text-muted normal-case tracking-normal">{{ $row['override_note'] }}</span>
                                    @endif
                                @else
                                    <span class="text-muted">None</span>
                                @endif
                            </dd>
                        </div>
                        <div class="flex flex-col gap-1">
                            <dt class="font-mono text-[0.6875rem] tracking-[0.12em] text-muted uppercase">Effective</dt>
                            <dd class="font-mono text-sm text-ink">{{ $formatLimit($row['effective_limit']) }}</dd>
                        </div>
                        <div class="flex flex-col gap-1">
                            <dt class="font-mono text-[0.6875rem] tracking-[0.12em] text-muted uppercase">
                                {{ $row['is_rate'] ? 'Usage '.$row['period']->current() : 'Current count' }}
                            </dt>
                            <dd class="font-mono text-sm text-ink">{{ number_format($row['used']) }}</dd>
                        </div>
                    </dl>

                    <form
                        class="flex flex-col gap-3 border-t border-line pt-4"
                        method="POST"
                        action="{{ route('admin.organizations.quota-overrides.store', $organization) }}"
                    >
                        @csrf
                        <input type="hidden" name="metric" value="{{ $metric->value }}">

                        <p class="font-mono text-[0.6875rem] tracking-[0.12em] text-muted uppercase">Set override</p>

                        <div class="grid gap-3 sm:grid-cols-2">
                            <x-input
                                name="limit"
                                label="Limit"
                                type="number"
                                min="0"
                                :value="$row['has_override'] && $row['override_limit'] !== null ? $row['override_limit'] : null"
                                hint="Non-negative integer. Leave blank when unlimited is checked."
                            />

                            <x-input
                                name="note"
                                label="Note"
                                :value="$row['override_note']"
                                hint="Optional. Shown on this page only."
                            />
                        </div>

                        <label class="flex items-start gap-3 text-sm text-ink">
                            <input
                                class="mt-1 size-4 rounded border-line-strong text-accent focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
                                type="checkbox"
                                name="unlimited"
                                value="1"
                                @checked(old('metric') === $metric->value ? old('unlimited') : ($row['has_override'] && $row['override_limit'] === null))
                            >
                            <span>Unlimited for this metric</span>
                        </label>

                        <x-textarea
                            name="reason"
                            label="Reason"
                            rows="2"
                            required
                            hint="Required for the audit log."
                        />

                        <x-button variant="ghost">{{ $row['has_override'] ? 'Update override' : 'Set override' }}</x-button>
                    </form>

                    @if ($row['has_override'])
                        <form
                            class="flex flex-col gap-3 border-t border-line pt-4"
                            method="POST"
                            action="{{ route('admin.organizations.quota-overrides.destroy', $organization) }}"
                            onsubmit="return confirm('Clear the {{ $row['label'] }} override? The plan limit will apply.')"
                        >
                            @csrf
                            @method('DELETE')
                            <input type="hidden" name="metric" value="{{ $metric->value }}">

                            <p class="font-mono text-[0.6875rem] tracking-[0.12em] text-muted uppercase">Clear override</p>

                            <x-textarea
                                name="reason"
                                label="Reason"
                                rows="2"
                                required
                            />

                            <x-button variant="ghost">Clear override</x-button>
                        </form>
                    @endif

                    @if ($row['is_rate'])
                        <form
                            class="flex flex-col gap-3 border-t border-line pt-4"
                            method="POST"
                            action="{{ route('admin.organizations.quota-periods.store', $organization) }}"
                        >
                            @csrf
                            <input type="hidden" name="metric" value="{{ $metric->value }}">

                            <p class="font-mono text-[0.6875rem] tracking-[0.12em] text-muted uppercase">Set period</p>

                            <x-select
                                name="period"
                                label="Period"
                                :options="['plan' => 'Plan default ('.$row['plan_period']->label().')'] + \App\Enums\QuotaPeriod::options()"
                                :value="old('metric') === $metric->value ? old('period') : ($row['has_period_setting'] ? $row['period']->value : 'plan')"
                                hint="UTC calendar periods: days start at 00:00, weeks on Monday, months on the 1st. A new period starts a fresh counter."
                            />

                            <x-textarea
                                name="reason"
                                label="Reason"
                                rows="2"
                                required
                                hint="Required for the audit log."
                            />

                            <x-button variant="ghost">Set period</x-button>
                        </form>
                    @endif

                    @if ($row['is_rate'])
                        <form
                            class="flex flex-col gap-3 border-t border-line pt-4"
                            method="POST"
                            action="{{ route('admin.organizations.quota-usage-resets.store', $organization) }}"
                            onsubmit="return confirm('Reset this metric usage to zero for the current period?')"
                        >
                            @csrf
                            <input type="hidden" name="metric" value="{{ $metric->value }}">

                            <p class="font-mono text-[0.6875rem] tracking-[0.12em] text-muted uppercase">Reset current period usage</p>

                            <x-textarea
                                name="reason"
                                label="Reason"
                                rows="2"
                                required
                                hint="Zeros the current period's counter only. Stock metrics cannot be reset here."
                            />

                            <x-button variant="ghost">Reset usage</x-button>
                        </form>
                    @endif
                </div>
            @endforeach
        </div>
    </x-card>


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
