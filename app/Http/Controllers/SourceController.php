<?php

namespace App\Http\Controllers;

use App\Http\Requests\DestroySourceRequest;
use App\Http\Requests\UpdateSourceRequest;
use App\Models\Broadcast;
use App\Models\Organization;
use App\Models\Source;
use App\Support\ConnectionTestOutcome;
use App\Support\Pipeline\PipelineEditorPayload;
use App\Support\SourceProviderRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class SourceController extends Controller
{
    public function __construct(
        private SourceProviderRegistry $providers,
        private PipelineEditorPayload $payload,
    ) {}

    public function edit(Organization $organization, Source $source): View
    {
        Gate::authorize('update', $source);

        return $this->form($source);
    }

    public function update(UpdateSourceRequest $request, Organization $organization, Source $source): RedirectResponse|JsonResponse
    {
        $source->update([
            'name' => $request->name(),
            'config' => $request->configuration(),
        ]);

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'The source has been updated.',
                'source' => $this->payload->source($source->refresh()),
            ]);
        }

        return redirect()
            ->route('sources.edit', $source)
            ->with('success', 'The source has been updated.');
    }

    public function destroy(DestroySourceRequest $request, Organization $organization, Source $source): RedirectResponse
    {
        $project = $source->project;

        DB::transaction(function () use ($source): void {
            Broadcast::query()->where('source_id', $source->getKey())->delete();
            $source->delete();
        });

        return redirect()
            ->route('broadcasts.show', $project)
            ->with('success', 'The source has been deleted.');
    }

    /**
     * @param  array<string, mixed>|null  $config
     */
    public function form(
        Source $source,
        ?string $name = null,
        ?array $config = null,
        ?bool $supportsConnectionTest = null,
        ?ConnectionTestOutcome $testOutcome = null,
    ): View {
        $known = $this->providers->has($source->type);
        $supports = $supportsConnectionTest ?? ($known && $this->providers->capabilities($source->type)->supportsConnectionTest);

        return view('sources.edit', [
            'source' => $source,
            'known' => $known,
            'typeLabel' => $known ? $this->providers->get($source->type)->label() : $source->type,
            'fields' => $known ? $this->providers->configurationFields($source->type) : [],
            'formName' => $name ?? $source->name,
            'formConfig' => $config ?? ($source->config ?? []),
            'supportsConnectionTest' => $supports,
            'testOutcome' => $testOutcome,
        ]);
    }
}
