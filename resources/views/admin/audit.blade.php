<x-layout title="Admin audit">
    <x-card heading="Audit" kicker="Admin" eared>
        <p class="text-sm text-muted">
            Every admin sign-in and moderation change, newest first. Times use your profile timezone ({{ auth()->user()->timezone }}).
        </p>

        <form class="flex flex-wrap items-end gap-3" method="GET" action="{{ route('admin.audit.index') }}">
            <label class="flex flex-col gap-1.5 text-sm">
                <span class="font-medium text-ink">Admin</span>
                <select class="rounded-md border border-line-strong bg-paper-raised px-3 py-2 text-ink" name="admin_id">
                    <option value="" @selected($filters['admin_id'] === null)>All</option>
                    @foreach ($admins as $admin)
                        <option value="{{ $admin->id }}" @selected($filters['admin_id'] === $admin->id)>{{ $admin->name }} ({{ $admin->email }})</option>
                    @endforeach
                </select>
            </label>

            <label class="flex flex-col gap-1.5 text-sm">
                <span class="font-medium text-ink">Action</span>
                <select class="rounded-md border border-line-strong bg-paper-raised px-3 py-2 text-ink" name="action">
                    <option value="" @selected($filters['action'] === null)>All</option>
                    @foreach ($actionOptions as $value => $label)
                        <option value="{{ $value }}" @selected($filters['action'] === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>

            <label class="flex flex-col gap-1.5 text-sm">
                <span class="font-medium text-ink">Subject type</span>
                <select class="rounded-md border border-line-strong bg-paper-raised px-3 py-2 text-ink" name="subject_type">
                    <option value="" @selected($filters['subject_type'] === '')>All</option>
                    @foreach ($subjectTypes as $type)
                        <option value="{{ $type }}" @selected($filters['subject_type'] === $type)>{{ class_basename($type) }}</option>
                    @endforeach
                </select>
            </label>

            <label class="flex flex-col gap-1.5 text-sm">
                <span class="font-medium text-ink">Subject id</span>
                <input
                    class="w-28 rounded-md border border-line-strong bg-paper-raised px-3 py-2 text-ink"
                    name="subject_id"
                    type="number"
                    min="1"
                    value="{{ $filters['subject_id'] }}"
                >
            </label>

            <x-button :paw="false">Filter</x-button>
        </form>

        @if ($actions->isEmpty())
            <x-empty-state
                heading="No audit entries yet"
                message="Admin sign-ins and moderation changes will appear here."
            />
        @else
            <ul class="flex flex-col divide-y divide-line border-t border-line">
                @foreach ($actions as $entry)
                    <li class="flex flex-col gap-1 py-4">
                        <div class="flex flex-wrap items-baseline justify-between gap-2">
                            <span class="text-sm text-ink">
                                <span class="font-medium">{{ $entry->action->label() }}</span>
                                · {{ $entry->admin?->name ?? 'Unknown admin' }}
                            </span>
                            <span class="font-mono text-xs text-muted">
                                <x-timestamp :value="$entry->created_at" />
                            </span>
                        </div>

                        <p class="font-mono text-xs text-muted">
                            Subject: {{ $entry->subjectLabel() }}
                            @if ($entry->ip_address)
                                · IP {{ $entry->ip_address }}
                            @endif
                        </p>

                        @if ($entry->reason)
                            <p class="text-sm text-muted">Reason: {{ $entry->reason }}</p>
                        @endif

                        @if (is_array($entry->changes) && $entry->changes !== [])
                            <pre class="overflow-x-auto rounded-md border border-line bg-paper p-3 font-mono text-xs text-muted">{{ json_encode($entry->changes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                        @endif
                    </li>
                @endforeach
            </ul>

            <div class="pt-4">
                {{ $actions->links() }}
            </div>
        @endif
    </x-card>
</x-layout>