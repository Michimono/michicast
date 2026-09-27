<?php

namespace App\Http\Controllers;

use App\Models\Broadcast;
use App\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class ArchivedBroadcastController extends Controller
{
    public function store(Organization $organization, Broadcast $broadcast): RedirectResponse
    {
        Gate::authorize('changeStatus', $broadcast);

        $broadcast->archive();

        return redirect()
            ->route('broadcast-pipelines.index', $broadcast->project)
            ->with('success', 'The broadcast has been archived.');
    }

    public function destroy(Organization $organization, Broadcast $broadcast): RedirectResponse
    {
        Gate::authorize('changeStatus', $broadcast);

        $broadcast->restore();

        return redirect()
            ->route('broadcast-pipelines.index', $broadcast->project)
            ->with('success', 'The broadcast has been restored.');
    }
}
