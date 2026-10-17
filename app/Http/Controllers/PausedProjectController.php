<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\Project;
use App\Support\Pipeline\PipelineEditorPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class PausedProjectController extends Controller
{
    public function __construct(private PipelineEditorPayload $payload) {}

    public function store(Request $request, Organization $organization, Project $project): RedirectResponse|JsonResponse
    {
        Gate::authorize('changeStatus', $project);

        $project->pause();

        if ($request->expectsJson()) {
            return $this->json($project, 'The broadcast has been paused.');
        }

        return redirect()
            ->to($this->afterMutation($request, $organization, $project))
            ->with('success', 'The broadcast has been paused.');
    }

    public function destroy(Request $request, Organization $organization, Project $project): RedirectResponse|JsonResponse
    {
        Gate::authorize('changeStatus', $project);

        $project->resume();

        if ($request->expectsJson()) {
            return $this->json($project, 'The broadcast has been resumed.');
        }

        return redirect()
            ->to($this->afterMutation($request, $organization, $project))
            ->with('success', 'The broadcast has been resumed.');
    }

    private function json(Project $project, string $message): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'project' => $this->payload->project($project->refresh()),
        ]);
    }

    private function afterMutation(Request $request, Organization $organization, Project $project): string
    {
        if ($request->string('return')->toString() === 'organization') {
            return route('organizations.show', $organization);
        }

        return route('broadcasts.show', $project);
    }
}
