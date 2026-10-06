<x-layout title="Admin users">
    <x-card heading="Users" kicker="Admin" eared>
        <p class="text-sm text-muted">
            Every account on the platform. Search by name or email to find someone for moderation.
        </p>

        <form class="flex flex-wrap items-end gap-3" method="GET" action="{{ route('admin.users.index') }}">
            <label class="flex min-w-64 flex-1 flex-col gap-1.5 text-sm">
                <span class="font-medium text-ink">Search</span>
                <input
                    class="rounded-md border border-line-strong bg-paper-raised px-3 py-2 text-ink"
                    name="q"
                    type="search"
                    value="{{ $search }}"
                    placeholder="Name or email"
                >
            </label>

            <x-button :paw="false">Search</x-button>
        </form>

        @if ($users->isEmpty())
            <x-empty-state
                heading="No users found"
                message="Try a different name or email fragment."
            />
        @else
            <div class="overflow-x-auto border-t border-line">
                <table class="w-full min-w-[40rem] text-left text-sm">
                    <thead>
                        <tr class="border-b border-line font-mono text-[0.6875rem] tracking-[0.12em] text-muted uppercase">
                            <th class="py-3 pr-4 font-medium">User</th>
                            <th class="py-3 pr-4 font-medium">Created</th>
                            <th class="py-3 pr-4 font-medium">Last seen</th>
                            <th class="py-3 pr-4 font-medium">Orgs</th>
                            <th class="py-3 pr-4 font-medium">Status</th>
                            <th class="py-3 font-medium">Flags</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($users as $listedUser)
                            <tr>
                                <td class="py-3 pr-4">
                                    <a
                                        class="font-medium text-accent underline hover:no-underline"
                                        href="{{ route('admin.users.show', $listedUser) }}"
                                    >{{ $listedUser->name }}</a>
                                    <p class="font-mono text-xs text-muted">{{ $listedUser->email }}</p>
                                </td>
                                <td class="py-3 pr-4 font-mono text-xs text-muted">
                                    <x-timestamp :value="$listedUser->created_at" />
                                </td>
                                <td class="py-3 pr-4 font-mono text-xs text-muted">
                                    @if ($listedUser->last_seen_at)
                                        <x-timestamp :value="\Illuminate\Support\Carbon::createFromTimestamp((int) $listedUser->last_seen_at)" />
                                    @else
                                        Never
                                    @endif
                                </td>
                                <td class="py-3 pr-4 font-mono text-xs text-ink">{{ $listedUser->organizations_count }}</td>
                                <td class="py-3 pr-4">
                                    <span class="inline-flex w-fit rounded-full border border-state-ok/40 px-2 py-0.5 font-mono text-xs tracking-wide text-state-ok uppercase">Active</span>
                                </td>
                                <td class="py-3">
                                    @if ($listedUser->isPlatformAdmin())
                                        <span class="inline-flex w-fit rounded-full border border-line px-2 py-0.5 font-mono text-xs tracking-wide text-muted uppercase">Admin</span>
                                    @else
                                        <span class="text-xs text-muted">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="pt-4">
                {{ $users->links() }}
            </div>
        @endif
    </x-card>
</x-layout>
