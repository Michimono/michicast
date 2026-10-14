<?php

namespace App\Support;

use App\Models\Project;
use Illuminate\View\View;

/**
 * Builds the new-source screen for both the create page and a preview
 * re-render, so the type tiles and every type's field group stay identical.
 */
class SourceFormData
{
    public function __construct(private SourceProviderRegistry $providers) {}

    /**
     * @param  array<string, mixed>  $extra
     */
    public function view(Project $project, string $type, array $extra = []): View
    {
        $types = $this->providers->options();

        if (! array_key_exists($type, $types)) {
            $types[$type] = $this->providers->get($type)->label();
        }

        $typeGroups = [];

        foreach ($types as $option => $label) {
            $typeGroups[$option] = [
                'label' => $label,
                'presentation' => SourceTypePresentation::for($option, $label),
                'fields' => $this->providers->configurationFields($option),
                'supportsPreview' => $this->providers->capabilities($option)->supportsConnectionTest,
            ];
        }

        return view('project-sources.create', [
            'project' => $project,
            'type' => $type,
            'typeGroups' => $typeGroups,
            ...$extra,
        ]);
    }
}
