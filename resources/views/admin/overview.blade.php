<x-layout title="Admin overview">
    <x-card heading="Overview" kicker="Admin" eared>
        <p class="text-sm text-muted">
            Platform-wide counts. Moderation and deeper tooling land in later issues.
        </p>

        <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <div class="flex flex-col gap-1 rounded-md border border-line bg-paper p-4">
                <dt class="font-mono text-[0.6875rem] tracking-[0.12em] text-muted uppercase">Users</dt>
                <dd class="font-display text-2xl tracking-tight">{{ $userCount }}</dd>
            </div>

            <div class="flex flex-col gap-1 rounded-md border border-line bg-paper p-4">
                <dt class="font-mono text-[0.6875rem] tracking-[0.12em] text-muted uppercase">Organizations</dt>
                <dd class="font-display text-2xl tracking-tight">{{ $organizationCount }}</dd>
            </div>

            <div class="flex flex-col gap-1 rounded-md border border-line bg-paper p-4">
                <dt class="font-mono text-[0.6875rem] tracking-[0.12em] text-muted uppercase">Active broadcasts</dt>
                <dd class="font-display text-2xl tracking-tight">{{ $activeBroadcastCount }}</dd>
            </div>
        </dl>
    </x-card>

    <x-card heading="Runs in the last 24 hours" kicker="Activity">
        <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($runCounts as $status => $count)
                <div class="flex flex-col gap-1 rounded-md border border-line bg-paper p-4">
                    <dt class="font-mono text-[0.6875rem] tracking-[0.12em] text-muted uppercase">{{ \App\Enums\RunStatus::from($status)->label() }}</dt>
                    <dd class="font-display text-2xl tracking-tight">{{ $count }}</dd>
                </div>
            @endforeach
        </dl>
    </x-card>

    @if ($failedJobCount !== null)
        <x-card heading="Failed jobs" kicker="Horizon">
            <p class="font-display text-2xl tracking-tight">{{ $failedJobCount }}</p>
            <p class="text-sm text-muted">Rows in the failed_jobs table.</p>
        </x-card>
    @endif
</x-layout>