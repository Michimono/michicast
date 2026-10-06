<?php

namespace App\Http\Controllers;

use App\Jobs\SendLatestJob;
use App\Models\ContentItem;
use App\Models\Organization;
use App\Models\Project;
use App\Support\SecretRedactor;
use App\Support\SendLatest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Throwable;

class BroadcastSendLatestController extends Controller
{
    public function store(
        Organization $organization,
        Project $project,
        SendLatest $sendLatest,
        SecretRedactor $secrets,
    ): RedirectResponse {
        Gate::authorize('sendLatest', $project);

        if (! $project->canMarkDue()) {
            return redirect()
                ->route('broadcasts.edit', $project)
                ->with('error', 'Only an active broadcast can send the latest item.');
        }

        if (config('michicast.runs_on_queue')) {
            $hasItem = ContentItem::query()
                ->where('project_id', $project->getKey())
                ->exists();

            if (! $hasItem) {
                return redirect()
                    ->route('broadcasts.edit', $project)
                    ->with('error', 'No received source item to send.');
            }

            SendLatestJob::dispatch((int) $project->getKey());

            return redirect()
                ->route('broadcasts.edit', $project)
                ->with('success', 'Queued');
        }

        try {
            $result = $sendLatest->handle($project);
        } catch (Throwable $e) {
            report($e);

            return redirect()
                ->route('broadcasts.edit', $project)
                ->with('error', 'Send latest could not finish: '.$secrets->redact($e->getMessage()));
        }

        if ($result === null) {
            return redirect()
                ->route('broadcasts.edit', $project)
                ->with('error', 'No received source item to send.');
        }

        $label = $result['item']->title
            ?? $result['item']->canonical_url
            ?? $result['item']->external_id
            ?? 'latest item';

        if ($result['delivered'] > 0) {
            $message = 'Published “'.$label.'” to the destination.';
        } elseif ($result['skipped'] > 0) {
            $message = '“'.$label.'” was not published (filtered or no matching runnable route).';
        } else {
            $message = 'No matching runnable broadcast published “'.$label.'”.';
        }

        return redirect()
            ->route('broadcasts.edit', $project)
            ->with('success', $message)
            ->with('last_run_id', $result['projectRun']->getKey());
    }
}
