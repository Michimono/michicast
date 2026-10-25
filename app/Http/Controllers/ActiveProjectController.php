<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\Project;
use App\Support\Pipeline\PipelineEditorPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ActiveProjectController extends Controller
{
    public function store(
        Request $request,
        Organization $organization,
        Project $project,
        PipelineEditorPayload $payload,
    ): RedirectResponse|JsonResponse {
        Gate::authorize('changeStatus', $project);

        $project->activate();

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'The broadcast has been activated.',
                'project' => $payload->project($project->refresh()),
            ]);
        }

        return redirect()
            ->route('broadcasts.show', $project)
            ->with('success', 'The broadcast has been activated.');
    }
}
