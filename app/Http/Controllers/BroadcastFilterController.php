<?php

namespace App\Http\Controllers;

use App\Enums\BroadcastFilterRuleType;
use App\Http\Requests\UpdateBroadcastFilterRequest;
use App\Models\Broadcast;
use App\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class BroadcastFilterController extends Controller
{
    public function edit(Organization $organization, Broadcast $broadcast): View
    {
        Gate::authorize('update', $broadcast);

        return view('broadcast-filters.edit', [
            'broadcast' => $broadcast,
            'rules' => old('rules', $broadcast->filter['rules'] ?? []),
            'types' => BroadcastFilterRuleType::options(),
            'fields' => BroadcastFilterRuleType::fieldOptions(),
        ]);
    }

    public function update(UpdateBroadcastFilterRequest $request, Organization $organization, Broadcast $broadcast): RedirectResponse
    {
        $broadcast->update([
            'filter' => $request->filter(),
        ]);

        return redirect()
            ->route('broadcast-filters.edit', $broadcast)
            ->with('success', 'The filters have been saved.');
    }
}
