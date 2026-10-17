<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdatePipelineRequest;
use App\Models\Organization;
use App\Models\Project;
use App\Support\Pipeline\PipelineEditorPayload;
use App\Support\Pipeline\PipelineGraphCompiler;
use App\Support\Pipeline\PipelineRouteSynchronizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * The pipeline of a visual broadcast: a singleton under the project. Edit
 * renders the canvas; update stores the graph and recompiles its routes.
 * Running, testing and status changes stay on their own resources.
 */
class PipelineController extends Controller
{
    public function __construct(
        private PipelineGraphCompiler $compiler,
        private PipelineRouteSynchronizer $routes,
        private PipelineEditorPayload $payload,
    ) {}

    public function edit(Organization $organization, Project $project): View|RedirectResponse
    {
        Gate::authorize('view', $project);

        if (! $project->isVisual()) {
            return redirect()
                ->route('broadcasts.show', $project)
                ->with('error', 'This broadcast is managed from its overview, not the canvas.');
        }

        $project->setRelation('organization', $organization);
        $graph = $project->pipelineGraph();
        $compilation = $this->compiler->compile($graph, $project);

        return view('pipelines.edit', [
            'project' => $project,
            'editor' => $this->payload->build(
                $project,
                $graph,
                $compilation,
                canEdit: Gate::allows('update', $project),
            ),
        ]);
    }

    public function update(UpdatePipelineRequest $request, Organization $organization, Project $project): JsonResponse|RedirectResponse
    {
        abort_unless($project->isVisual(), 404);

        $project->setRelation('organization', $organization);
        $graph = $request->graph();
        $compilation = $this->compiler->compile($graph, $project);

        $this->routes->sync($project, $compilation->routes);

        $project->graph = $graph->toArray();
        $project->save();
        $project->refresh();
        $project->setRelation('organization', $organization);

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'saved_at' => now()->toIso8601String(),
                'graph' => $graph->toArray(),
                'issues' => $compilation->issuesToArray(),
                'routes' => $this->payload->routes($project, $compilation),
                'project' => $this->payload->project($project),
            ]);
        }

        return redirect()
            ->route('pipelines.edit', $project)
            ->with('success', 'The pipeline has been saved.');
    }
}
