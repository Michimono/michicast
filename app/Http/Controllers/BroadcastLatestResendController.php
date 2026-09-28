<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\Project;
use App\Support\AllowLatestResend;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class BroadcastLatestResendController extends Controller
{
    public function store(
        Organization $organization,
        Project $project,
        AllowLatestResend $allowLatestResend,
    ): RedirectResponse {
        Gate::authorize('allowLatestResend', $project);

        $result = $allowLatestResend->handle($project);

        if ($result === null) {
            return redirect()
                ->route('broadcasts.edit', $project)
                ->with('error', 'No received source item to allow a resend for.');
        }

        $label = $result['item']->title
            ?? $result['item']->canonical_url
            ?? $result['item']->external_id
            ?? 'latest item';

        return redirect()
            ->route('broadcasts.edit', $project)
            ->with('success', 'Resend allowed for “'.$label.'”. Use Run now when you are ready to publish.');
    }
}
