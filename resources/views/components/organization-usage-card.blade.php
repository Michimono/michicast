<x-card id="usage" heading="Usage" kicker="Quota" eared class="scroll-mt-8 max-w-md">
    <p class="text-sm text-ink">
        Plan: <span class="font-medium">{{ ucfirst($planName) }}</span>.
        Usage limits reset at the start of each UTC day, week (Monday) or month, shown in your display timezone.
    </p>

    <dl class="flex flex-col gap-3">
        @foreach ($rows as $row)
            <div class="flex flex-col gap-1 rounded-md border border-line bg-paper p-3">
                <dt class="font-mono text-[0.6875rem] tracking-[0.12em] text-muted uppercase">{{ $row['label'] }}</dt>
                <dd class="flex flex-wrap items-baseline justify-between gap-2 text-sm text-ink">
                    <span class="font-mono">
                        {{ number_format($row['used']) }}
                        <span class="text-muted">/</span>
                        @if ($row['limit'] === null)
                            Unlimited
                        @else
                            {{ number_format($row['limit']) }}
                        @endif
                        @if ($row['period'] !== null)
                            <span class="font-sans text-muted">{{ $row['period']->current() }}</span>
                        @endif
                    </span>
                    @if ($row['resets_at'] !== null)
                        <span class="text-xs text-muted">
                            Resets <x-timestamp :value="$row['resets_at']" />
                        </span>
                    @endif
                </dd>
            </div>
        @endforeach
    </dl>
</x-card>