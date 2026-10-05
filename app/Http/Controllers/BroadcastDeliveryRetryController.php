<?php

namespace App\Http\Controllers;

use App\Models\Broadcast;
use App\Models\ContentDelivery;
use App\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class BroadcastDeliveryRetryController extends Controller
{
    /**
     * Queue every retryable failed delivery on a broadcast, so the next
     * dispatcher tick republishes them all in one run. Nothing is published
     * in this request.
     */
    public function store(Organization $organization, Broadcast $broadcast): RedirectResponse
    {
        Gate::authorize('retryDeliveries', $broadcast);

        $blockReason = $broadcast->retryBlockReason();

        if ($blockReason !== null) {
            return redirect()
                ->route('broadcast-deliveries.index', $broadcast)
                ->with('error', $blockReason);
        }

        $queued = ContentDelivery::query()
            ->retryableOn($broadcast)
            ->whereNull('retry_requested_at')
            ->update(['retry_requested_at' => now()]);

        if ($queued === 0) {
            return redirect()
                ->route('broadcast-deliveries.index', $broadcast)
                ->with('error', 'No failed delivery on this broadcast can be retried.');
        }

        $broadcast->project->markDue();

        return redirect()
            ->route('broadcast-deliveries.index', $broadcast)
            ->with('success', 'Queued '.$queued.' failed '.Str::plural('delivery', $queued).' for the next scheduler tick, within about a minute.');
    }
}
