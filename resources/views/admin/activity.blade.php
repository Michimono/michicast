<x-layout title="Admin activity">
    <x-card heading="Needs attention" kicker="Admin" eared>
        <p class="text-sm text-muted">
            Auto-paused broadcasts, organizations out of quota today, and the noisiest tenants in the last 24 hours.
        </p>

        <div class="grid gap-6 lg:grid-cols-2">
            <div class="flex flex-col gap-3">
                <h3 class="font-mono text-[0.6875rem] tracking-[0.12em] text-muted uppercase">Auto-paused broadcasts</h3>
                @if ($autoPaused->isEmpty())
                    <p class="text-sm text-muted">None right now.</p>
                @else
                    <ul class="flex flex-col divide-y divide-line border-t border-line">
                        @foreach ($autoPaused as $broadcast)
                            <li class="flex flex-col gap-1 py-3">
                                <div class="flex flex-wrap items-baseline justify-between gap-2">
                                    <span class="text-sm font-medium text-ink">{{ $broadcast->name }}</span>
                                    @if ($broadcast->project?->organization)
                                        <a
                                            class="font-mono text-xs text-accent underline hover:no-underline"
                                            href="{{ route('admin.organizations.show', $broadcast->project->organization) }}"
                                        >{{ $broadcast->project->organization->name }}</a>
                                    @endif
                                </div>
                                @if ($broadcast->failure_pause_reason)
                                    <p class="text-sm text-state-fail">{{ $secrets->redact($broadcast->failure_pause_reason) }}</p>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            <div class="flex flex-col gap-3">
                <h3 class="font-mono text-[0.6875rem] tracking-[0.12em] text-muted uppercase">Out of quota today</h3>
                @if ($outOfQuota === [])
                    <p class="text-sm text-muted">No organizations have hit a daily rate limit.</p>
                @else
                    <ul class="flex flex-col divide-y divide-line border-t border-line">
                        @foreach ($outOfQuota as $row)
                            <li class="flex flex-wrap items-baseline justify-between gap-2 py-3">
                                <a
                                    class="text-sm font-medium text-accent underline hover:no-underline"
                                    href="{{ route('admin.organizations.show', $row['organization']) }}"
                                >{{ $row['organization']->name }}</a>
                                <span class="font-mono text-xs text-state-warn">
                                    {{ $row['metric']->label() }} · {{ $row['used'] }} / {{ $row['limit'] }}
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            <div class="flex flex-col gap-3">
                <h3 class="font-mono text-[0.6875rem] tracking-[0.12em] text-muted uppercase">Top fetches (24h)</h3>
                @if ($topByFetches->isEmpty())
                    <p class="text-sm text-muted">No broadcast runs in the last 24 hours.</p>
                @else
                    <ul class="flex flex-col divide-y divide-line border-t border-line">
                        @foreach ($topByFetches as $row)
                            <li class="flex flex-wrap items-baseline justify-between gap-2 py-3">
                                <a
                                    class="text-sm font-medium text-accent underline hover:no-underline"
                                    href="{{ route('admin.organizations.show', $row->organization_id) }}"
                                >{{ $row->name }}</a>
                                <span class="font-mono text-xs text-ink">{{ $row->aggregate }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            <div class="flex flex-col gap-3">
                <h3 class="font-mono text-[0.6875rem] tracking-[0.12em] text-muted uppercase">Top deliveries (24h)</h3>
                @if ($topByDeliveries->isEmpty())
                    <p class="text-sm text-muted">No deliveries in the last 24 hours.</p>
                @else
                    <ul class="flex flex-col divide-y divide-line border-t border-line">
                        @foreach ($topByDeliveries as $row)
                            <li class="flex flex-wrap items-baseline justify-between gap-2 py-3">
                                <a
                                    class="text-sm font-medium text-accent underline hover:no-underline"
                                    href="{{ route('admin.organizations.show', $row->organization_id) }}"
                                >{{ $row->name }}</a>
                                <span class="font-mono text-xs text-ink">{{ $row->aggregate }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    </x-card>

    <x-card heading="Activity" kicker="Admin">
        <p class="text-sm text-muted">
            Recent broadcast runs across every organization, newest first. Times use your profile timezone ({{ auth()->user()->timezone }}).
        </p>

        <form class="flex flex-wrap items-end gap-3" method="GET" action="{{ route('admin.activity') }}">
            <label class="flex flex-col gap-1.5 text-sm">
                <span class="font-medium text-ink">Status</span>
                <select class="rounded-md border border-line-strong bg-paper-raised px-3 py-2 text-ink" name="status">
                    <option value="" @selected($filters['status'] === null)>All</option>
                    @foreach ($statusOptions as $value => $label)
                        <option value="{{ $value }}" @selected($filters['status'] === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>

            <label class="flex flex-col gap-1.5 text-sm">
                <span class="font-medium text-ink">Organization</span>
                <select class="rounded-md border border-line-strong bg-paper-raised px-3 py-2 text-ink" name="organization_id">
                    <option value="" @selected($filters['organization_id'] === null)>All</option>
                    @foreach ($organizations as $organization)
                        <option value="{{ $organization->id }}" @selected($filters['organization_id'] === $organization->id)>{{ $organization->name }}</option>
                    @endforeach
                </select>
            </label>

            <label class="flex flex-col gap-1.5 text-sm">
                <span class="font-medium text-ink">Source type</span>
                <select class="rounded-md border border-line-strong bg-paper-raised px-3 py-2 text-ink" name="source_type">
                    <option value="" @selected($filters['source_type'] === '')>All</option>
                    @foreach ($sourceTypeOptions as $value => $label)
                        <option value="{{ $value }}" @selected($filters['source_type'] === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>

            <label class="flex flex-col gap-1.5 text-sm">
                <span class="font-medium text-ink">Time window</span>
                <select class="rounded-md border border-line-strong bg-paper-raised px-3 py-2 text-ink" name="window">
                    <option value="" @selected($filters['window'] === '')>Any time</option>
                    @foreach ($timeWindows as $value => $label)
                        <option value="{{ $value }}" @selected($filters['window'] === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>

            <x-button :paw="false">Filter</x-button>
        </form>

        @if ($runs->isEmpty())
            <x-empty-state
                heading="No broadcast runs match"
                message="Widen the filters or wait for the next dispatcher tick."
            />
        @else
            <div class="overflow-x-auto border-t border-line">
                <table class="w-full min-w-[56rem] text-left text-sm">
                    <thead>
                        <tr class="border-b border-line font-mono text-[0.6875rem] tracking-[0.12em] text-muted uppercase">
                            <th class="py-3 pr-4 font-medium">Started</th>
                            <th class="py-3 pr-4 font-medium">Organization</th>
                            <th class="py-3 pr-4 font-medium">Broadcast</th>
                            <th class="py-3 pr-4 font-medium">Source → Destination</th>
                            <th class="py-3 pr-4 font-medium">Status</th>
                            <th class="py-3 pr-4 font-medium">Counts</th>
                            <th class="py-3 font-medium">Error</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($runs as $run)
                            @php
                                $broadcast = $run->broadcast;
                                $organization = $broadcast?->project?->organization;
                            @endphp
                            <tr>
                                <td class="py-3 pr-4 font-mono text-xs text-muted whitespace-nowrap">
                                    <x-timestamp :value="$run->started_at" />
                                </td>
                                <td class="py-3 pr-4">
                                    @if ($organization)
                                        <a
                                            class="font-medium text-accent underline hover:no-underline"
                                            href="{{ route('admin.organizations.show', $organization) }}"
                                        >{{ $organization->name }}</a>
                                    @else
                                        <span class="text-muted">Unknown</span>
                                    @endif
                                </td>
                                <td class="py-3 pr-4 text-ink">{{ $broadcast?->name ?? 'Broadcast #'.$run->broadcast_id }}</td>
                                <td class="py-3 pr-4 font-mono text-xs text-muted">
                                    {{ $broadcast?->source?->type ?? '—' }}
                                    →
                                    {{ $broadcast?->destination?->type ?? '—' }}
                                </td>
                                <td class="py-3 pr-4">
                                    <x-run-status :status="$run->status" />
                                </td>
                                <td class="py-3 pr-4 font-mono text-xs text-muted whitespace-nowrap">
                                    found {{ $run->items_found }}
                                    · new {{ $run->items_new }}
                                    · skipped {{ $run->items_skipped }}
                                    · delivered {{ $run->items_delivered }}
                                    · failed {{ $run->items_failed }}
                                </td>
                                <td class="py-3 text-sm">
                                    @if ($run->error_detail)
                                        <span @class([
                                            'text-state-warn' => $run->status === \App\Enums\RunStatus::QuotaExceeded,
                                            'text-state-fail' => $run->status !== \App\Enums\RunStatus::QuotaExceeded,
                                        ])>{{ $secrets->redact($run->error_detail) }}</span>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="pt-4">
                {{ $runs->links() }}
            </div>
        @endif
    </x-card>
</x-layout>