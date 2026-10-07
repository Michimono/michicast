<x-layout title="Admin organizations">
    <x-card heading="Organizations" kicker="Admin" eared>
        <p class="text-sm text-muted">
            Every tenant on the platform. Search by name or slug to open a workspace for moderation.
        </p>

        <form class="flex flex-wrap items-end gap-3" method="GET" action="{{ route('admin.organizations.index') }}">
            <label class="flex min-w-64 flex-1 flex-col gap-1.5 text-sm">
                <span class="font-medium text-ink">Search</span>
                <input
                    class="rounded-md border border-line-strong bg-paper-raised px-3 py-2 text-ink"
                    name="q"
                    type="search"
                    value="{{ $search }}"
                    placeholder="Name or slug"
                >
            </label>

            <x-button :paw="false">Search</x-button>
        </form>

        @if ($organizations->isEmpty())
            <x-empty-state
                heading="No organizations found"
                message="Try a different name or slug fragment."
            />
        @else
            <div class="overflow-x-auto border-t border-line">
                <table class="w-full min-w-[48rem] text-left text-sm">
                    <thead>
                        <tr class="border-b border-line font-mono text-[0.6875rem] tracking-[0.12em] text-muted uppercase">
                            <th class="py-3 pr-4 font-medium">Organization</th>
                            <th class="py-3 pr-4 font-medium">Plan</th>
                            <th class="py-3 pr-4 font-medium">Members</th>
                            <th class="py-3 pr-4 font-medium">Active broadcasts</th>
                            <th class="py-3 pr-4 font-medium">Runs 24h</th>
                            <th class="py-3 pr-4 font-medium">Deliveries 24h</th>
                            <th class="py-3 font-medium">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($organizations as $listedOrganization)
                            <tr>
                                <td class="py-3 pr-4">
                                    <a
                                        class="font-medium text-accent underline hover:no-underline"
                                        href="{{ route('admin.organizations.show', $listedOrganization) }}"
                                    >{{ $listedOrganization->name }}</a>
                                    <p class="font-mono text-xs text-muted">{{ $listedOrganization->slug }}</p>
                                </td>
                                <td class="py-3 pr-4 text-sm text-ink">{{ ucfirst((string) $listedOrganization->plan) }}</td>
                                <td class="py-3 pr-4 font-mono text-xs text-ink">{{ $listedOrganization->members_count }}</td>
                                <td class="py-3 pr-4 font-mono text-xs text-ink">{{ $listedOrganization->active_broadcasts_count }}</td>
                                <td class="py-3 pr-4 font-mono text-xs text-ink">{{ $listedOrganization->runs_last_24h_count }}</td>
                                <td class="py-3 pr-4 font-mono text-xs text-ink">{{ $listedOrganization->deliveries_last_24h_count }}</td>
                                <td class="py-3">
                                    <span class="inline-flex w-fit rounded-full border border-state-ok/40 px-2 py-0.5 font-mono text-xs tracking-wide text-state-ok uppercase">Active</span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="pt-4">
                {{ $organizations->links() }}
            </div>
        @endif
    </x-card>
</x-layout>
