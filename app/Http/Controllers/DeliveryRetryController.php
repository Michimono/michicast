<?php

namespace App\Http\Controllers;

use App\Models\Broadcast;
use App\Models\ContentDelivery;
use App\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class DeliveryRetryController extends Controller
{
    /**
     * Queue one failed delivery for the next dispatcher tick. Nothing is
     * transformed or published in this request.
     */
    public function store(Organization $organization, Broadcast $broadcast, ContentDelivery $delivery): RedirectResponse
    {
        Gate::authorize('retryDeliveries', $broadcast);

        $delivery->setRelation('broadcast', $broadcast);

        if (! $delivery->isRetryable()) {
            return redirect()
                ->route('broadcast-deliveries.index', $broadcast)
                ->with('error', 'Only a delivery that failed for a temporary reason can be retried.');
        }

        $blockReason = $broadcast->retryBlockReason();

        if ($blockReason !== null) {
            return redirect()
                ->route('broadcast-deliveries.index', $broadcast)
                ->with('error', $blockReason);
        }

        $delivery->requestRetry();
        $broadcast->project->markDue();

        return redirect()
            ->route('broadcast-deliveries.index', $broadcast)
            ->with('success', 'The retry is queued for the next scheduler tick, within about a minute.');
    }
}
