<?php

namespace App\Http\Controllers;

use App\Enums\BroadcastTransformRuleType;
use App\Http\Requests\UpdateBroadcastTransformRequest;
use App\Models\Broadcast;
use App\Models\Organization;
use App\Support\BroadcastTransforms\TemplatePlaceholders;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class BroadcastTransformController extends Controller
{
    public function edit(Organization $organization, Broadcast $broadcast): View
    {
        Gate::authorize('update', $broadcast);

        return $this->form($broadcast);
    }

    public function update(UpdateBroadcastTransformRequest $request, Organization $organization, Broadcast $broadcast): RedirectResponse
    {
        $broadcast->update([
            'transform' => $request->transform(),
        ]);

        return redirect()
            ->route('broadcast-transforms.edit', $broadcast)
            ->with('success', 'The transform has been saved.');
    }

    /**
     * @param  array<string, mixed>|null  $previewTransform
     */
    public function form(
        Broadcast $broadcast,
        ?array $previewTransform = null,
        ?string $previewBody = null,
    ): View {
        $transform = $previewTransform ?? $broadcast->transform;

        return view('broadcast-transforms.edit', [
            'broadcast' => $broadcast,
            'template' => old('template', $transform['template'] ?? TemplatePlaceholders::DEFAULT_TEMPLATE),
            'rules' => old('rules', $transform['rules'] ?? []),
            'types' => BroadcastTransformRuleType::options(),
            'placeholders' => TemplatePlaceholders::descriptions(),
            'previewBody' => $previewBody,
            'defaultTemplate' => TemplatePlaceholders::DEFAULT_TEMPLATE,
        ]);
    }
}
