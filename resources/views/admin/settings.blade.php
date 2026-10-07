<x-layout title="Admin settings">
    <x-card id="registration" heading="Registration" kicker="Settings" eared>
        <p class="text-sm text-muted">
            Controls who can create an account. Changes take effect on the next request and are written to the audit log.
            @if ($isStored)
                Values are stored in the database and override the environment defaults.
            @else
                Using the environment defaults (<code class="font-mono">MICHICAST_REGISTRATION={{ $configuredMode->value }}</code>@if ($configuredDailyCap !== null), daily cap {{ number_format($configuredDailyCap) }}@endif) until an admin saves a change.
            @endif
        </p>

        <dl class="grid gap-4 sm:grid-cols-3">
            <div class="flex flex-col gap-1 rounded-md border border-line bg-paper p-4">
                <dt class="font-mono text-[0.6875rem] tracking-[0.12em] text-muted uppercase">Right now</dt>
                <dd class="font-display text-2xl tracking-tight">{{ $effectiveMode->label() }}</dd>
                @if ($capReached)
                    <dd class="text-sm text-state-fail">Daily cap reached; closed until midnight UTC.</dd>
                @endif
            </div>

            <div class="flex flex-col gap-1 rounded-md border border-line bg-paper p-4">
                <dt class="font-mono text-[0.6875rem] tracking-[0.12em] text-muted uppercase">New accounts today</dt>
                <dd class="font-display text-2xl tracking-tight">{{ number_format($registrationsToday) }}</dd>
            </div>

            <div class="flex flex-col gap-1 rounded-md border border-line bg-paper p-4">
                <dt class="font-mono text-[0.6875rem] tracking-[0.12em] text-muted uppercase">Daily cap</dt>
                <dd class="font-display text-2xl tracking-tight">{{ $dailyCap === null ? 'None' : number_format($dailyCap) }}</dd>
            </div>
        </dl>

        <ul class="flex flex-col gap-2 text-sm">
            @foreach ($modes as $option)
                <li>
                    <span class="font-medium text-ink">{{ $option->label() }}:</span>
                    <span class="text-muted">{{ $option->description() }}</span>
                </li>
            @endforeach
        </ul>

        <form
            class="flex flex-col gap-4 rounded-md border border-line bg-paper p-4"
            method="POST"
            action="{{ route('admin.settings.update') }}"
        >
            @csrf
            @method('PATCH')

            <x-select
                name="mode"
                label="Registration mode"
                :options="$modeOptions"
                :value="$mode->value"
                required
            />

            <x-input
                name="daily_cap"
                label="Daily cap on new accounts"
                type="number"
                min="1"
                :value="$dailyCap"
                hint="Optional. Once this many accounts are created in a day (UTC), registration behaves as closed until the next day. Leave blank for no cap."
            />

            <x-textarea
                name="reason"
                label="Reason"
                rows="2"
                required
                hint="Required. Stored in the audit log with the before and after values."
            />

            <x-button>Save registration settings</x-button>
        </form>
    </x-card>
</x-layout>
