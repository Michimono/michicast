<x-layout :title="$broadcast->name.' deliveries'">
    <x-card heading="Delivery history" :kicker="$broadcast->name" eared>
        <p class="text-sm text-muted">
            {{ $broadcast->source->name }} → {{ $broadcast->destination->name }}.
            Times use your profile timezone ({{ auth()->user()->timezone }}).
        </p>

        <form class="flex flex-wrap items-end gap-3" method="GET" action="{{ route('broadcast-deliveries.index', $broadcast) }}">
            <label class="flex flex-col gap-1.5 text-sm">
                <span class="font-medium text-ink">Status</span>
                <select class="rounded-md border border-line-strong bg-paper-raised px-3 py-2 text-ink" name="status">
                    <option value="" @selected($status === null)>All</option>
                    @foreach ($statuses as $option)
                        <option value="{{ $option->value }}" @selected($status === $option)>{{ $option->label() }}</option>
                    @endforeach
                </select>
            </label>

            <x-button :paw="false">Filter</x-button>
        </form>

        <p class="flex flex-wrap gap-2">
            <x-button href="{{ route('broadcast-pipelines.index', $project) }}" variant="ghost" :paw="false">Broadcasts</x-button>
            <x-button href="{{ route('broadcasts.show', $project) }}" variant="ghost" :paw="false">Project</x-button>
        </p>

        @if ($deliveries->isEmpty())
            <x-empty-state
                heading="No deliveries yet"
                message="Publishes for this broadcast will appear here with their status and errors."
            />
        @else
            <ul class="flex flex-col divide-y divide-line border-t border-line">
                @foreach ($deliveries as $delivery)
                    <li class="flex flex-col gap-1 py-4">
                        <div class="flex flex-wrap items-baseline justify-between gap-2">
                            <span class="text-sm text-ink">
                                {{ $delivery->contentItem?->title ?: ($delivery->contentItem?->canonical_url ?: 'Item #'.$delivery->content_item_id) }}
                            </span>
                            <span @class([
                                'font-mono text-xs',
                                'text-state-ok' => $delivery->status === App\Enums\DeliveryStatus::Delivered,
                                'text-state-fail' => $delivery->status === App\Enums\DeliveryStatus::Failed,
                                'text-state-skip' => $delivery->status === App\Enums\DeliveryStatus::Skipped,
                                'text-muted' => $delivery->status === App\Enums\DeliveryStatus::Pending,
                            ])>{{ $delivery->status->label() }}</span>
                        </div>

                        <p class="font-mono text-xs text-muted">
                            <x-timestamp :value="$delivery->attempted_at" />
                            · {{ $delivery->destination?->name }}
                        </p>

                        @if ($delivery->error_detail)
                            <p class="text-sm text-muted">{{ $secrets->redact($delivery->error_detail) }}</p>
                        @endif

                        <div class="flex flex-wrap gap-3 text-xs">
                            @if ($delivery->contentItem?->canonical_url)
                                <a class="text-accent hover:underline" href="{{ $delivery->contentItem->canonical_url }}" rel="noopener noreferrer" target="_blank">Canonical URL</a>
                            @endif
                            @if ($delivery->external_reference)
                                <span class="font-mono text-muted">Ref {{ $delivery->external_reference }}</span>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>

            <div class="pt-4">
                {{ $deliveries->links() }}
            </div>
        @endif
    </x-card>
</x-layout>
