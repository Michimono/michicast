<?php

namespace App\Http\Controllers;

use App\Http\Requests\DestroyDestinationRequest;
use App\Http\Requests\UpdateDestinationRequest;
use App\Models\Broadcast;
use App\Models\Destination;
use App\Models\Organization;
use App\Support\ConnectionTestOutcome;
use App\Support\DestinationProviderRegistry;
use App\Support\SecretRedactor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class DestinationController extends Controller
{
    public function __construct(
        private DestinationProviderRegistry $providers,
        private SecretRedactor $secrets,
    ) {}

    public function edit(Organization $organization, Destination $destination): View
    {
        Gate::authorize('update', $destination);

        return $this->form($destination);
    }

    public function update(UpdateDestinationRequest $request, Organization $organization, Destination $destination): RedirectResponse
    {
        $destination->update([
            'name' => $request->name(),
            'config' => $request->configuration(),
            'credentials' => $request->credentials(),
        ]);

        return redirect()
            ->route('destinations.edit', $destination)
            ->with('success', 'The destination has been updated.');
    }

    public function destroy(DestroyDestinationRequest $request, Organization $organization, Destination $destination): RedirectResponse
    {
        $project = $destination->project;

        DB::transaction(function () use ($destination): void {
            Broadcast::query()->where('destination_id', $destination->getKey())->delete();
            $destination->delete();
        });

        return redirect()
            ->route('broadcasts.show', $project)
            ->with('success', 'The destination has been deleted.');
    }

    /**
     * @param  array<string, mixed>|null  $config
     */
    public function form(
        Destination $destination,
        ?string $name = null,
        ?array $config = null,
        ?bool $supportsConnectionTest = null,
        ?ConnectionTestOutcome $testOutcome = null,
    ): View {
        $known = $this->providers->has($destination->type);
        $supports = $supportsConnectionTest ?? ($known && $this->providers->capabilities($destination->type)->supportsConnectionTest);

        return view('destinations.edit', [
            'destination' => $destination,
            'known' => $known,
            'typeLabel' => $known ? $this->providers->get($destination->type)->label() : $destination->type,
            'fields' => $known ? $this->providers->configurationFields($destination->type) : [],
            'credentialFields' => $known ? $this->providers->credentialFields($destination->type) : [],
            'credentialMasks' => array_map(
                fn (mixed $value): ?string => $this->secrets->mask($value),
                $destination->credentials ?? [],
            ),
            'formName' => $name ?? $destination->name,
            'formConfig' => $config ?? ($destination->config ?? []),
            'supportsConnectionTest' => $supports,
            'testOutcome' => $testOutcome,
        ]);
    }
}
