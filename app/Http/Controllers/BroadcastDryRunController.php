<?php

namespace App\Http\Controllers;

use App\Enums\RunStatus;
use App\Models\BroadcastRun;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectRun;
use App\Support\ExecutingProjectRunner;
use App\Support\Pipeline\PipelineGraphCompiler;
use App\Support\Pipeline\PipelineTraceRecorder;
use App\Support\SecretRedactor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Throwable;

class BroadcastDryRunController extends Controller
{
    public function store(
        Request $request,
        Organization $organization,
        Project $project,
        ExecutingProjectRunner $runner,
        SecretRedactor $secrets,
        PipelineGraphCompiler $compiler,
        PipelineTraceRecorder $traces,
    ): RedirectResponse|JsonResponse {
        Gate::authorize('dryRun', $project);

        try {
            $run = $runner->dryRun($project);

            // A visual broadcast also keeps what crossed each connection, so the
            // editor can show the flow edge by edge. Nothing here publishes.
            $edgeTraces = [];

            if ($project->isVisual()) {
                $project->setRelation('organization', $organization);
                $graph = $project->pipelineGraph();
                $stored = $traces->record($project, $graph, $compiler->compile($graph, $project), $run);

                foreach ($stored as $trace) {
                    $edgeTraces[] = $traces->toArray($trace, withItems: false);
                }
            }
        } catch (Throwable $e) {
            report($e);

            $message = 'The dry run could not finish: '.$secrets->redact($e->getMessage());

            if ($request->expectsJson()) {
                return response()->json(['ok' => false, 'message' => $message], 500);
            }

            return redirect()
                ->route('broadcasts.edit', $project)
                ->with('error', $message);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => $run->status !== RunStatus::QuotaExceeded,
                'message' => $run->status === RunStatus::QuotaExceeded
                    ? ($this->quotaDetail($run) ?: $run->status->label())
                    : 'Dry run finished. Nothing was published.',
                'run' => $this->report($run),
                'traces' => $edgeTraces,
            ]);
        }

        if ($run->status === RunStatus::QuotaExceeded) {
            return redirect()
                ->route('broadcasts.edit', $project)
                ->with('error', $this->quotaDetail($run) ?: $run->status->label())
                ->with('last_run_id', $run->getKey());
        }

        return redirect()
            ->route('broadcasts.edit', $project)
            ->with('success', 'Dry run finished. Nothing was published.')
            ->with('last_run_id', $run->getKey());
    }

    private function quotaDetail(ProjectRun $run): ?string
    {
        $detail = $run->broadcastRuns()
            ->where('status', RunStatus::QuotaExceeded)
            ->value('error_detail');

        return is_string($detail) && $detail !== '' ? $detail : null;
    }

    /**
     * The dry run as the pipeline editor shows it: one entry per route with
     * what would have published or been skipped, and why.
     *
     * @return array<string, mixed>
     */
    private function report(ProjectRun $run): array
    {
        $broadcastRuns = $run->broadcastRuns()
            ->with('broadcast')
            ->orderBy('id')
            ->get();

        return [
            'id' => $run->getKey(),
            'status' => $run->status->value,
            'status_label' => $run->status->label(),
            'items_found' => $run->items_found,
            'items_skipped' => $run->items_skipped,
            'would_publish' => $run->items_delivered,
            'items_failed' => $run->items_failed,
            'routes' => $broadcastRuns->map(fn (BroadcastRun $broadcastRun): array => [
                'broadcast_id' => $broadcastRun->broadcast_id,
                'name' => $broadcastRun->broadcast?->name,
                'source_id' => $broadcastRun->broadcast?->source_id,
                'destination_id' => $broadcastRun->broadcast?->destination_id,
                'status' => $broadcastRun->status->value,
                'status_label' => $broadcastRun->status->label(),
                'error_detail' => $broadcastRun->error_detail,
                'items_found' => $broadcastRun->items_found,
                'items_skipped' => $broadcastRun->items_skipped,
                'would_publish' => $broadcastRun->items_delivered,
                'report' => $broadcastRun->dry_report ?? [],
            ])->values()->all(),
        ];
    }
}
