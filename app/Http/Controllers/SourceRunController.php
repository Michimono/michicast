<?php

namespace App\Http\Controllers;

use App\Enums\RunStatus;
use App\Jobs\RunProject;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectRun;
use App\Models\Source;
use App\Support\Pipeline\PipelineEditorPayload;
use App\Support\ProjectRunner;
use App\Support\SecretRedactor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * Run now for one source (#241): fetch only this source and run the live
 * routes that read it, delivering for real. The source's own schedule is
 * left as it is.
 */
class SourceRunController extends Controller
{
    public function __construct(
        private ProjectRunner $runner,
        private SecretRedactor $secrets,
        private PipelineEditorPayload $payload,
    ) {}

    public function store(Request $request, Organization $organization, Source $source): RedirectResponse|JsonResponse
    {
        /** @var Project $project */
        $project = $source->project()->with('organization')->firstOrFail();

        Gate::authorize('markDue', $project);

        if (! $project->canMarkDue()) {
            return $this->respond($request, $project, $source, error: 'Only an active broadcast can run now.', status: 409);
        }

        $live = ! $source->isArchived() && $project->broadcasts()
            ->runnable()
            ->where('source_id', $source->getKey())
            ->exists();

        if (! $live) {
            return $this->respond($request, $project, $source, error: 'This source is not on a live route, so there is nothing to run.', status: 409);
        }

        $sourceIds = [(int) $source->getKey()];

        if (config('michicast.runs_on_queue')) {
            return $this->queueRun($request, $project, $source, $sourceIds);
        }

        try {
            $this->runner->run($project, null, $sourceIds);
        } catch (Throwable $e) {
            report($e);

            return $this->respond(
                $request,
                $project,
                $source,
                error: 'The run could not finish: '.$this->secrets->redact($e->getMessage()),
                status: 500,
            );
        }

        $run = $project->runs()->where('is_dry', false)->latest('id')->first();

        if ($run !== null && $run->status === RunStatus::QuotaExceeded) {
            $detail = $run->broadcastRuns()
                ->where('status', RunStatus::QuotaExceeded)
                ->value('error_detail');

            return $this->respond($request, $project, $source, error: $detail ?: $run->status->label(), status: 409, run: $run);
        }

        return $this->respond(
            $request,
            $project,
            $source,
            success: $run === null ? 'The run finished.' : 'Run finished: '.$run->status->label().'.',
            run: $run,
        );
    }

    /**
     * @param  list<int>  $sourceIds
     */
    private function queueRun(Request $request, Project $project, Source $source, array $sourceIds): RedirectResponse|JsonResponse
    {
        $project->markDue();
        $project->refresh();

        $claimedAt = now();
        $staleBefore = $claimedAt->copy()->subSeconds(max(0, (int) config('michicast.dispatch.stale_claim_after')));

        if (! $project->claimDueRun($claimedAt, $staleBefore)) {
            return $this->respond($request, $project, $source, error: 'This broadcast is already running. Try again in a moment.', status: 409);
        }

        RunProject::dispatch((int) $project->getKey(), $claimedAt->toIso8601String(), $sourceIds);

        return $this->respond($request, $project, $source, success: 'Queued. The source runs in a moment.', queued: true);
    }

    private function respond(
        Request $request,
        Project $project,
        Source $source,
        ?string $success = null,
        ?string $error = null,
        int $status = 200,
        ?ProjectRun $run = null,
        bool $queued = false,
    ): RedirectResponse|JsonResponse {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => $error ?? $success,
                'queued' => $queued,
                'run' => $run === null ? null : [
                    'id' => $run->getKey(),
                    'status' => $run->status->value,
                    'status_label' => $run->status->label(),
                    'url' => route('broadcast-runs.show', [$project, $run]),
                ],
                'project' => $this->payload->project($project->refresh()),
                'source' => $this->payload->source($source->refresh()),
            ], $error === null ? 200 : $status);
        }

        $redirect = redirect()->route('broadcasts.show', $project);

        if ($error !== null) {
            $redirect->with('error', $error);
        } else {
            $redirect->with('success', $success);
        }

        return $run === null ? $redirect : $redirect->with('last_run_id', $run->getKey());
    }
}
