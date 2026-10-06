<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreEnabledBroadcastRequest;
use App\Models\Broadcast;
use App\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class EnabledBroadcastController extends Controller
{
    public function store(StoreEnabledBroadcastRequest $request, Organization $organization, Broadcast $broadcast): RedirectResponse
    {
        $broadcast->enable();

        return back()->with('success', 'The broadcast has been enabled.');
    }

    public function destroy(Organization $organization, Broadcast $broadcast): RedirectResponse
    {
        Gate::authorize('changeStatus', $broadcast);

        $broadcast->disable();

        return back()->with('success', 'The broadcast has been disabled.');
    }
}
