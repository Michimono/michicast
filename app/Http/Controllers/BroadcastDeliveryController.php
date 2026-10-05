<?php

namespace App\Http\Controllers;

use App\Enums\DeliveryStatus;
use App\Models\Broadcast;
use App\Models\ContentDelivery;
use App\Models\Organization;
use App\Support\SecretRedactor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class BroadcastDeliveryController extends Controller
{
    public function __construct(private SecretRedactor $secrets) {}

    public function index(Request $request, Organization $organization, Broadcast $broadcast): View
    {
        Gate::authorize('view', $broadcast->project);

        $broadcast->load(['source', 'destination', 'project']);

        $status = $this->statusFilter($request);

        $deliveries = $broadcast->deliveries()
            ->with(['contentItem', 'destination', 'attempts'])
            ->when(
                $status !== null,
                fn ($query) => $query->where('status', $status->value),
            )
            ->latest('attempted_at')
            ->latest('id')
            ->paginate(30)
            ->withQueryString();

        $deliveries->getCollection()->each(
            fn (ContentDelivery $delivery) => $delivery->setRelation('broadcast', $broadcast),
        );

        return view('broadcast-deliveries.index', [
            'broadcast' => $broadcast,
            'project' => $broadcast->project,
            'deliveries' => $deliveries,
            'status' => $status,
            'statuses' => DeliveryStatus::cases(),
            'retryableCount' => ContentDelivery::query()
                ->retryableOn($broadcast)
                ->whereNull('retry_requested_at')
                ->count(),
            'retryBlockReason' => $broadcast->retryBlockReason(),
            'canRetry' => $request->user()?->can('retryDeliveries', $broadcast) ?? false,
            'secrets' => $this->secrets,
        ]);
    }

    private function statusFilter(Request $request): ?DeliveryStatus
    {
        $raw = $request->string('status')->toString();

        if ($raw === '') {
            return null;
        }

        return DeliveryStatus::tryFrom($raw);
    }
}
