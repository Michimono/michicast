<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateBroadcastRequest;
use App\Models\Broadcast;
use App\Models\Organization;
use App\Models\Project;
use App\Support\BroadcastPreviewResult;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class BroadcastController extends Controller
{
    public function edit(Organization $organization, Broadcast $broadcast): View
    {
        Gate::authorize('update', $broadcast);

        return $this->form($broadcast);
    }

    public function update(UpdateBroadcastRequest $request, Organization $organization, Broadcast $broadcast): RedirectResponse
    {
        $broadcast->update([
            'name' => $request->name(),
            'source_id' => $request->sourceId(),
            'destination_id' => $request->destinationId(),
        ]);

        return redirect()
            ->route('broadcasts.edit', $broadcast)
            ->with('success', 'The broadcast has been updated.');
    }

    public function form(
        Broadcast $broadcast,
        ?BroadcastPreviewResult $preview = null,
        ?string $previewError = null,
    ): View {
        $project = $broadcast->project;

        return view('broadcasts.edit', [
            'broadcast' => $broadcast->loadMissing(['source', 'destination']),
            'sources' => $this->sourceOptions($project, $broadcast),
            'destinations' => $this->destinationOptions($project, $broadcast),
            'preview' => $preview,
            'previewError' => $previewError,
        ]);
    }

    /**
     * Live sources plus the one already on the broadcast, so an archived
     * selection still shows until the editor picks another.
     *
     * @return array<int, string>
     */
    private function sourceOptions(Project $project, Broadcast $broadcast): array
    {
        return $project->sources()
            ->where(function ($query) use ($broadcast): void {
                $query->whereNull('archived_at')
                    ->orWhereKey($broadcast->source_id);
            })
            ->oldest('id')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function destinationOptions(Project $project, Broadcast $broadcast): array
    {
        return $project->destinations()
            ->where(function ($query) use ($broadcast): void {
                $query->whereNull('archived_at')
                    ->orWhereKey($broadcast->destination_id);
            })
            ->oldest('id')
            ->pluck('name', 'id')
            ->all();
    }
}
