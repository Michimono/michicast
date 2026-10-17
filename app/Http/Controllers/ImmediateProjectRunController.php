<?php

namespace App\Http\Controllers;

use App\Enums\RunStatus;
use App\Jobs\RunProject;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectRun;
use App\Support\Pipeline\PipelineEditorPayload;
use App\Support\ProjectRunner;
use App\Support\SecretRedactor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Throwable;

class ImmediateProjectRunController extends Controller
{
    public function __construct(
        private ProjectRunner $runner,
        private SecretRedactor $secrets,
        private PipelineEditorPayload $payload,
    ) {}

    public function store(Request $request, Organization $organization, Project $project): RedirectResponse|JsonResponse
    {
        Gate::authorize('markDue', $project);

        if (! $project->canMarkDue()) {
            return $this->respond($request, $project, error: 'Only an active broadcast can run now.', status: 409);
        }

        if (config('michicast.runs_on_queue')) {
            return $this->queueRun($request, $project);
        }

        try {
            $this->runner->run($project);
        } catch (Throwable $e) {
            report($e);

            return $this->respond(
                $request,
                $project,
                error: 'The run could not finish: '.$this->secrets->redact($e->getMessage()),
                status: 500,
            );
        }

        $run = $project->runs()->where('is_dry', false)->latest('id')->first();

        if ($run !== null && $run->status === RunStatus::QuotaExceeded) {
            $detail = $run->broadcastRuns()
                ->where('status', RunStatus::QuotaExceeded)
                ->value('error_detail');

            return $this->respond($request, $project, error: $detail ?: $run->status->label(), status: 409, run: $run);
        }

        return $this->respond(
            $request,
            $project,
            success: $run === null ? 'The run finished.' : 'Run finished: '.$run->status->label().'.',
            run: $run,
        );
    }

    private function queueRun(Request $request, Project $project): RedirectResponse|JsonResponse
    {
        $project->markDue();
        $project->refresh();

        $claimedAt = now();
        $staleBefore = $claimedAt->copy()->subSeconds(max(0, (int) config('michicast.dispatch.stale_claim_after')));

        if ($project->claimDueRun($claimedAt, $staleBefore)) {
            RunProject::dispatch((int) $project->getKey(), $claimedAt->toIso8601String());
        }

        return $this->respond($request, $project, success: 'Queued', queued: true);
    }

    /**
     * The editor runs a broadcast without leaving the canvas and gets the
     * outcome as JSON; every other caller keeps the redirect with a flash.
     */
    private function respond(
        Request $request,
        Project $project,
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
