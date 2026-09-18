<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateSourceRequest;
use App\Models\Organization;
use App\Models\Source;
use App\Support\ConnectionTestOutcome;
use App\Support\SourceProviderRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class SourceController extends Controller
{
    public function __construct(private SourceProviderRegistry $providers) {}

    public function edit(Organization $organization, Source $source): View
    {
        Gate::authorize('update', $source);

        return $this->form($source);
    }

    public function update(UpdateSourceRequest $request, Organization $organization, Source $source): RedirectResponse
    {
        $source->update([
            'name' => $request->name(),
            'config' => $request->configuration(),
        ]);

        return redirect()
            ->route('sources.edit', $source)
            ->with('success', 'The source has been updated.');
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
