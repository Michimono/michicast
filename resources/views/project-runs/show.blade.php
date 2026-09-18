<x-layout :title="'Run '.$run->started_at->timezone(auth()->user()->timezone)->format('M j, Y g:i A')">
    <x-card heading="Run detail" :kicker="$project->name" eared>
        <p class="text-sm text-muted">
            Viewer timezone {{ auth()->user()->timezone }}. Project schedule timezone
            {{ $project->effectiveTimezone() }}.
        </p>

        <dl class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <div>
                <dt class="text-xs font-medium tracking-wide text-muted uppercase">Started</dt>
                <dd class="text-sm text-ink"><x-timestamp :value="$run->started_at" /></dd>
            </div>
            <div>
                <dt class="text-xs font-medium tracking-wide text-muted uppercase">Finished</dt>
                <dd class="text-sm text-ink">
                    @if ($run->finished_at)
                        <x-timestamp :value="$run->finished_at" />
                    @else
                        Still running
                    @endif
                </dd>
            </div>
            <div>
                <dt class="text-xs font-medium tracking-wide text-muted uppercase">Status</dt>
                <dd class="text-sm text-ink">
                    {{ $run->status->label() }}
                    @if ($run->is_dry)
                        <span class="font-mono text-xs text-muted">(dry run)</span>
                    @endif
                </dd>
            </div>
            <div>
                <dt class="text-xs font-medium tracking-wide text-muted uppercase">Found / new</dt>
                <dd class="font-mono text-sm text-ink">{{ $run->items_found }} / {{ $run->items_new }}</dd>
            </div>
            <div>
                <dt class="text-xs font-medium tracking-wide text-muted uppercase">
                    Skipped / {{ $run->is_dry ? 'would deliver' : 'delivered' }} / failed
                </dt>
                <dd class="font-mono text-sm text-ink">
                    <span class="text-state-skip">{{ $run->items_skipped }}</span>
                    /
                    <span class="text-state-ok">{{ $run->items_delivered }}</span>
                    /
                    <span class="text-state-fail">{{ $run->items_failed }}</span>
                </dd>
            </div>
        </dl>

        <p class="flex flex-wrap gap-2">
            <x-button href="{{ route('project-runs.index', $project) }}" variant="ghost" :paw="false">All runs</x-button>
            <x-button href="{{ route('projects.show', $project) }}" variant="ghost" :paw="false">Project</x-button>
        </p>
    </x-card>

    @forelse ($run->broadcastRuns as $broadcastRun)
        <x-card
            :heading="$broadcastRun->broadcast?->name ?? 'Broadcast #'.$broadcastRun->broadcast_id"
            kicker="Broadcast run"
        >
            <p class="font-mono text-xs text-muted">
                {{ $broadcastRun->status->label() }}
                · found {{ $broadcastRun->items_found }}
                · new {{ $broadcastRun->items_new }}
                · skipped {{ $broadcastRun->items_skipped }}
                · {{ $run->is_dry ? 'would deliver' : 'delivered' }} {{ $broadcastRun->items_delivered }}
                · failed {{ $broadcastRun->items_failed }}
            </p>

            @if ($broadcastRun->broadcast)
                <p class="text-sm text-muted">
                    {{ $broadcastRun->broadcast->source->name }}
                    →
                    {{ $broadcastRun->broadcast->destination->name }}
                </p>
                <p>
                    <x-button
                        href="{{ route('broadcast-deliveries.index', $broadcastRun->broadcast) }}"
                        variant="ghost"
                        :paw="false"
                    >
                        Delivery history
                    </x-button>
                </p>
            @endif

            @if ($broadcastRun->error_detail)
                <p class="rounded-md border border-line bg-paper px-3 py-2 text-sm text-state-fail">
                    {{ $secrets->redact($broadcastRun->error_detail) }}
                </p>
            @endif

            @if ($run->is_dry && is_array($broadcastRun->dry_report) && $broadcastRun->dry_report !== [])
                <ul class="flex flex-col divide-y divide-line border-t border-line">
                    @foreach ($broadcastRun->dry_report as $entry)
                        <li class="flex flex-col gap-1 py-3">
                            <div class="flex flex-wrap items-baseline justify-between gap-2">
                                <span class="text-sm text-ink">{{ $entry['title'] ?? 'Untitled item' }}</span>
                                <span @class([
                                    'font-mono text-xs',
                                    'text-state-ok' => ($entry['outcome'] ?? '') === 'publish',
                                    'text-state-skip' => ($entry['outcome'] ?? '') !== 'publish',
                                ])>
                                    {{ ($entry['outcome'] ?? '') === 'publish' ? 'Would publish' : 'Would skip' }}
                                </span>
                            </div>

                            @if (! empty($entry['skip_reason']))
                                <p class="text-sm text-muted">{{ $secrets->redact($entry['skip_reason']) }}</p>
                            @endif

                            @if (! empty($entry['rendered']))
                                <p class="whitespace-pre-wrap text-sm text-ink">{{ $entry['rendered'] }}</p>
                            @endif

                            @if (! empty($entry['canonical_url']))
                                <a class="text-xs text-accent hover:underline" href="{{ $entry['canonical_url'] }}" rel="noopener noreferrer" target="_blank">
                                    Canonical URL
                                </a>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @else
            @php
                $outcomes = $broadcastRun->deliveries
                    ->map(fn ($delivery) => [
                        'kind' => $delivery->status->label(),
                        'title' => $delivery->contentItem?->title ?: ($delivery->contentItem?->canonical_url ?: 'Item #'.$delivery->content_item_id),
                        'url' => $delivery->contentItem?->canonical_url,
                        'detail' => $secrets->redact($delivery->error_detail),
                        'reference' => $delivery->external_reference,
                        'status' => $delivery->status,
                    ])
                    ->concat(
                        $broadcastRun->skips->map(fn ($skip) => [
                            'kind' => 'Skipped',
                            'title' => $skip->contentItem?->title ?: ($skip->contentItem?->canonical_url ?: 'Item #'.$skip->content_item_id),
                            'url' => $skip->contentItem?->canonical_url,
                            'detail' => $secrets->redact($skip->reason),
                            'reference' => null,
                            'status' => App\Enums\DeliveryStatus::Skipped,
                        ])
                    );
            @endphp

            @if ($outcomes->isEmpty())
                <p class="text-sm text-muted">No per-item outcomes were recorded for this broadcast run.</p>
            @else
                <ul class="flex flex-col divide-y divide-line border-t border-line">
                    @foreach ($outcomes as $outcome)
                        <li class="flex flex-col gap-1 py-3">
                            <div class="flex flex-wrap items-baseline justify-between gap-2">
                                <span class="text-sm text-ink">{{ $outcome['title'] }}</span>
                                <span @class([
                                    'font-mono text-xs',
                                    'text-state-ok' => $outcome['status'] === App\Enums\DeliveryStatus::Delivered,
                                    'text-state-fail' => $outcome['status'] === App\Enums\DeliveryStatus::Failed,
                                    'text-state-skip' => $outcome['status'] === App\Enums\DeliveryStatus::Skipped,
                                    'text-muted' => $outcome['status'] === App\Enums\DeliveryStatus::Pending,
                                ])>{{ $outcome['kind'] }}</span>
                            </div>

                            @if ($outcome['detail'])
                                <p class="text-sm text-muted">{{ $outcome['detail'] }}</p>
                            @endif

                            <div class="flex flex-wrap gap-3 text-xs">
                                @if ($outcome['url'])
                                    <a class="text-accent hover:underline" href="{{ $outcome['url'] }}" rel="noopener noreferrer" target="_blank">Canonical URL</a>
                                @endif
                                @if ($outcome['reference'])
                                    <span class="font-mono text-muted">Ref {{ $outcome['reference'] }}</span>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
            @endif
        </x-card>
    @empty
        <x-card heading="Broadcasts" kicker="Run detail">
            <p class="text-sm text-muted">This run did not process any broadcasts.</p>
        </x-card>
    @endforelse
</x-layout>
