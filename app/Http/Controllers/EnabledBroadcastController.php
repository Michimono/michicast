<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreEnabledBroadcastRequest;
use App\Models\Broadcast;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class EnabledBroadcastController extends Controller
{
    public function store(StoreEnabledBroadcastRequest $request, Organization $organization, Broadcast $broadcast): RedirectResponse|JsonResponse
    {
        $broadcast->enable();

        if ($request->expectsJson()) {
            return $this->json($broadcast, 'The broadcast has been enabled.');
        }

        return back()->with('success', 'The broadcast has been enabled.');
    }

    public function destroy(Request $request, Organization $organization, Broadcast $broadcast): RedirectResponse|JsonResponse
    {
        Gate::authorize('changeStatus', $broadcast);

        $broadcast->disable();

        if ($request->expectsJson()) {
            return $this->json($broadcast, 'The broadcast has been disabled.');
        }

        return back()->with('success', 'The broadcast has been disabled.');
    }

    /**
     * The pipeline editor re-enables a paused route from the canvas.
     */
    private function json(Broadcast $broadcast, string $message): JsonResponse
    {
        $broadcast->refresh();

        return response()->json([
            'message' => $message,
            'route' => [
                'broadcast_id' => $broadcast->getKey(),
                'enabled' => $broadcast->enabled,
                'paused_reason' => $broadcast->failure_pause_reason,
            ],
        ]);
    }
}
