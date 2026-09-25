<?php

namespace App\Http\Controllers;

use App\Exceptions\SourceFetchFailedException;
use App\Http\Requests\StoreProjectSourcePreviewRequest;
use App\Models\ContentItem;
use App\Models\Organization;
use App\Models\Project;
use App\Support\SourceDefinition;
use App\Support\SourceProviderRegistry;
use Illuminate\View\View;
use Throwable;

/**
 * Fetches a source configuration before it is saved, so the create screen can
 * show the latest entry without persisting a source or any content items.
 */
class ProjectSourcePreviewController extends Controller
{
    public function __construct(private SourceProviderRegistry $providers) {}

    public function store(
        StoreProjectSourcePreviewRequest $request,
        Organization $organization,
        Project $project,
    ): View {
        $type = $request->type();
        $config = $request->configuration();
        $previewEntry = null;
        $previewError = null;

        try {
            $items = $this->providers->get($type)->fetch(new SourceDefinition(
                type: $type,
                config: $config,
                projectId: (int) $project->getKey(),
                sourceId: 0,
            ));

            $previewEntry = $this->latestEntry($items);

            if ($previewEntry === null) {
                $previewError = 'Reached the feed but found no entries.';
            }
        } catch (SourceFetchFailedException $exception) {
            $previewError = $exception->getMessage();
        } catch (Throwable $exception) {
            report($exception);

            $previewError = 'The feed could not be previewed. Check the configuration and try again.';
        }

        return view('project-sources.create', [
            'project' => $project,
            'types' => $this->providers->options(),
            'type' => $type,
            'fields' => $this->providers->configurationFields($type),
            'formName' => $request->name(),
            'formConfig' => $config,
            'previewEntry' => $previewEntry,
            'previewError' => $previewError,
            'supportsPreview' => $this->providers->capabilities($type)->supportsConnectionTest,
        ]);
    }

    /**
     * @param  list<ContentItem>  $items
     */
    private function latestEntry(array $items): ?ContentItem
    {
        if ($items === []) {
            return null;
        }

        usort($items, function (ContentItem $left, ContentItem $right): int {
            $leftTime = $left->published_at?->getTimestamp() ?? PHP_INT_MIN;
            $rightTime = $right->published_at?->getTimestamp() ?? PHP_INT_MIN;

            return $rightTime <=> $leftTime;
        });

        return $items[0];
    }
}
