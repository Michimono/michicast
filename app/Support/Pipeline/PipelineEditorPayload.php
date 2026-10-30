<?php

namespace App\Support\Pipeline;

use App\Enums\BroadcastFilterRuleType;
use App\Enums\BroadcastTransformRuleType;
use App\Enums\PipelineNodeType;
use App\Enums\ScheduleFrequency;
use App\Models\Broadcast;
use App\Models\Destination;
use App\Models\PipelineEdgeTrace;
use App\Models\Project;
use App\Models\Source;
use App\Support\BroadcastTransforms\TemplatePlaceholders;
use App\Support\ConfigurationField;
use App\Support\DestinationProviderRegistry;
use App\Support\Mapping\DefaultMappings;
use App\Support\Mapping\ProviderSchemas;
use App\Support\ScheduleSummary;
use App\Support\SecretRedactor;
use App\Support\SourceProviderRegistry;
use DateTimeZone;
use Illuminate\Support\Facades\Gate;

/**
 * Everything the pipeline editor needs in one JSON document: the graph, the
 * project's records to pick from, the closed vocabularies for filters and
 * transforms, the provider forms it can render in its inspector, the compiled
 * state, the latest test traces, and the endpoints it talks to.
 */
class PipelineEditorPayload
{
    public function __construct(
        private SourceProviderRegistry $sourceProviders,
        private DestinationProviderRegistry $destinationProviders,
        private ScheduleSummary $scheduleSummary,
        private SecretRedactor $secrets,
        private ProviderSchemas $schemas,
        private PipelineTraceRecorder $traces,
        private DefaultMappings $defaultMappings,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(Project $project, PipelineGraph $graph, PipelineCompilation $compilation, bool $canEdit): array
    {
        $project->loadMissing('organization');

        $sources = $project->sources()->oldest('id')->get();
        $destinations = $project->destinations()->oldest('id')->get();

        return [
            'project' => $this->project($project),
            'can_edit' => $canEdit,
            'graph' => $graph->toArray(),
            'issues' => $compilation->issuesToArray(),
            'routes' => $this->routes($project, $compilation),
            'sources' => $sources->map(fn (Source $source): array => $this->source($source))->values()->all(),
            'destinations' => $destinations->map(fn (Destination $destination): array => $this->destination($destination))->values()->all(),
            'traces' => array_values(array_map(
                fn (PipelineEdgeTrace $trace): array => $this->traces->toArray($trace, withItems: false),
                $this->traces->latest($project),
            )),
            'catalog' => $this->catalog(
                $sources->pluck('type')->unique()->values()->all(),
                $destinations->pluck('type')->unique()->values()->all(),
            ),
            'settings' => $this->settings($project),
            'permissions' => [
                'change_status' => Gate::allows('changeStatus', $project),
                'mark_due' => Gate::allows('markDue', $project),
                'dry_run' => Gate::allows('dryRun', $project),
                'create_source' => Gate::allows('create', [Source::class, $project]),
                'create_destination' => Gate::allows('create', [Destination::class, $project]),
            ],
            'urls' => [
                'update' => route('pipelines.update', $project),
                'dry_run' => route('broadcast-dry-runs.store', $project),
                'activate' => route('active-broadcasts.store', $project),
                'pause' => route('paused-broadcasts.store', $project),
                'resume' => route('paused-broadcasts.destroy', $project),
                'run_now' => route('immediate-broadcast-runs.store', $project),
                'source_store' => route('broadcast-sources.store', $project),
                'destination_store' => route('broadcast-destinations.store', $project),
                'new_source' => route('broadcast-sources.create', $project),
                'new_destination' => route('broadcast-destinations.create', $project),
                'project_update' => route('broadcasts.update', $project),
                'runs' => route('broadcast-runs.index', $project),
                'edge_trace' => route('pipeline-edge-traces.show', [$project, '__EDGE__']),
                'settings' => route('broadcasts.edit', $project),
                'activity' => route('broadcast-runs.index', $project),
                'exit' => route('organizations.show', $project->organization),
                'sign_in' => route('session.create'),
            ],
            'csrf' => csrf_token(),
        ];
    }

    /**
     * The project state the editor's top bar reflects after a save or a
     * lifecycle change.
     *
     * @return array<string, mixed>
     */
    public function project(Project $project): array
    {
        $project->loadMissing('organization');

        return [
            'id' => $project->getKey(),
            'name' => $project->name,
            'description' => $project->description,
            'status' => $project->status->value,
            'status_label' => $project->status->label(),
            'can_activate' => $project->canActivate(),
            'activation_block_reason' => $project->activationBlockReason(),
            'can_pause' => $project->canPause(),
            'can_resume' => $project->canResume(),
            'can_mark_due' => $project->canMarkDue(),
            'schedule_summary' => $this->scheduleSummary->describe($project->schedule),
            'timezone' => $project->effectiveTimezone(),
            'next_run_at' => $project->next_run_at?->toIso8601String(),
        ];
    }

    /**
     * Compiled routes joined to the broadcasts rows backing them, so the
     * editor can show which destination nodes are live, disabled or paused.
     *
     * @return list<array<string, mixed>>
     */
    public function routes(Project $project, PipelineCompilation $compilation): array
    {
        $rows = $project->broadcasts()->notArchived()->get();
        $routes = [];

        foreach ($compilation->routes as $route) {
            $row = $rows->first(
                fn (Broadcast $broadcast): bool => $broadcast->route_key === $route->pathKey(),
            ) ?? $rows->first(
                fn (Broadcast $broadcast): bool => $broadcast->route_key === null
                    && (int) $broadcast->source_id === $route->sourceId
                    && (int) $broadcast->destination_id === $route->destinationId,
            );

            $routes[] = [
                'source_node_id' => $route->sourceNodeId,
                'destination_node_id' => $route->destinationNodeId,
                'node_ids' => $route->nodeIds,
                'name' => $route->name,
                'source_id' => $route->sourceId,
                'destination_id' => $route->destinationId,
                'broadcast_id' => $row?->getKey(),
                'enabled' => $row instanceof Broadcast && $row->enabled,
                'paused_reason' => $row instanceof Broadcast && $row->failure_pause_reason !== null
                    ? (string) $this->secrets->redact($row->failure_pause_reason)
                    : null,
                'enable_url' => $row === null ? null : route('enabled-flows.store', $row),
                'deliveries_url' => $row === null ? null : route('broadcast-deliveries.index', $row),
            ];
        }

        return $routes;
    }

    /**
     * One source as the editor lists, edits and summarizes it. Configuration
     * is visible (sources hold no secrets); nothing here is a credential.
     *
     * @return array<string, mixed>
     */
    public function source(Source $source): array
    {
        $known = $this->sourceProviders->has($source->type);

        return [
            'id' => $source->getKey(),
            'name' => $source->name,
            'type' => $source->type,
            'type_label' => $known ? $this->sourceProviders->get($source->type)->label() : $source->type,
            'config' => $source->config ?? [],
            'schedule' => $source->effectiveSchedule(),
            'follows_broadcast_schedule' => $source->schedule === null,
            'schedule_summary' => $this->scheduleSummary->describe($source->effectiveSchedule()),
            'next_run_at' => $source->next_run_at?->toIso8601String(),
            'archived' => $source->isArchived(),
            'consecutive_failures' => $source->consecutive_failures,
            'last_tested_at' => $source->last_tested_at?->toIso8601String(),
            'last_test_successful' => $source->last_test_successful,
            'last_test_message' => $source->last_test_message,
            'supports_connection_test' => $known && $this->sourceProviders->capabilities($source->type)->supportsConnectionTest,
            'edit_url' => route('sources.edit', $source),
            'update_url' => route('sources.update', $source),
            'test_url' => route('source-connection-tests.store', $source),
            'run_url' => route('source-runs.store', $source),
            'summary_url' => route('source-summaries.show', $source),
        ];
    }

    /**
     * One destination as the editor lists, edits and summarizes it. Secrets
     * never travel: a stored credential is only shown as a short mask.
     *
     * @return array<string, mixed>
     */
    public function destination(Destination $destination): array
    {
        $known = $this->destinationProviders->has($destination->type);
        $capabilities = $known ? $this->destinationProviders->capabilities($destination->type) : null;

        return [
            'id' => $destination->getKey(),
            'name' => $destination->name,
            'type' => $destination->type,
            'type_label' => $known ? $this->destinationProviders->get($destination->type)->label() : $destination->type,
            'config' => $destination->config ?? [],
            'credential_masks' => array_map(
                fn (mixed $value): ?string => $this->secrets->mask($value),
                $destination->credentials ?? [],
            ),
            'archived' => $destination->isArchived(),
            'consecutive_failures' => $destination->consecutive_failures,
            'last_tested_at' => $destination->last_tested_at?->toIso8601String(),
            'last_test_successful' => $destination->last_test_successful,
            'last_test_message' => $destination->last_test_message,
            'supports_connection_test' => $capabilities !== null && $capabilities->supportsConnectionTest,
            'edit_url' => route('destinations.edit', $destination),
            'update_url' => route('destinations.update', $destination),
            'test_url' => route('destination-connection-tests.store', $destination),
            'summary_url' => route('destination-summaries.show', $destination),
        ];
    }

    /**
     * The fields a provider's items carry (sources) or its messages accept
     * (destinations), straight from the mapping schema the engine uses.
     *
     * @return list<array<string, mixed>>
     */
    public function schemaFields(string $kind, string $type): array
    {
        $schema = $kind === 'source'
            ? $this->schemas->sourceSchema($type)
            : $this->schemas->destinationSchema($type);

        return $schema === null ? [] : $schema->toArray()['fields'];
    }

    /**
     * @param  list<string>  $extraSourceTypes  Types in use that the forms no longer offer.
     * @param  list<string>  $extraDestinationTypes
     * @return array<string, mixed>
     */
    private function catalog(array $extraSourceTypes = [], array $extraDestinationTypes = []): array
    {
        $sourceTypes = $this->sourceTypes($extraSourceTypes);
        $destinationTypes = $this->destinationTypes($extraDestinationTypes);

        return [
            'node_types' => array_map(static fn (PipelineNodeType $type): array => [
                'type' => $type->value,
                'label' => $type->label(),
                'category' => $type->category(),
                'has_input' => $type->hasInput(),
                'has_output' => $type->hasOutput(),
                'accepts' => $type->acceptedUpstreamTypes(),
            ], PipelineNodeType::cases()),
            'filter_rule_types' => array_map(static fn (BroadcastFilterRuleType $type): array => [
                'value' => $type->value,
                'label' => $type->label(),
                'needs_field' => $type->needsField(),
                'needs_value' => $type->needsValue(),
                'needs_hours' => $type->needsHours(),
            ], BroadcastFilterRuleType::cases()),
            'filter_fields' => BroadcastFilterRuleType::fieldOptions(),
            'transform_rule_types' => array_map(static fn (BroadcastTransformRuleType $type): array => [
                'value' => $type->value,
                'label' => $type->label(),
                'needs_length' => $type->needsLength(),
                'needs_value' => $type->needsValue(),
                'needs_pattern' => $type->needsPattern(),
            ], BroadcastTransformRuleType::cases()),
            'placeholders' => array_map(
                static fn (string $name, string $description): array => ['name' => $name, 'description' => $description],
                array_keys(TemplatePlaceholders::descriptions()),
                TemplatePlaceholders::descriptions(),
            ),
            'default_template' => TemplatePlaceholders::DEFAULT_TEMPLATE,
            'source_types' => $sourceTypes,
            'destination_types' => $destinationTypes,
            'default_mappings' => $this->defaultMappings->catalog(
                array_map(static fn (array $type): string => $type['type'], $sourceTypes),
                array_map(static fn (array $type): string => $type['type'], $destinationTypes),
            ),
            'limits' => [
                'nodes' => PipelineGraph::MAX_NODES,
                'edges' => PipelineGraph::MAX_EDGES,
            ],
        ];
    }

    /**
     * @param  list<string>  $extra
     * @return list<array<string, mixed>>
     */
    private function sourceTypes(array $extra): array
    {
        $offered = $this->sourceProviders->options();
        $types = [];

        foreach (array_unique([...array_keys($offered), ...$extra]) as $type) {
            if (! $this->sourceProviders->has($type)) {
                continue;
            }

            $types[] = [
                'type' => $type,
                'label' => $this->sourceProviders->get($type)->label(),
                'offered' => array_key_exists($type, $offered),
                'fields' => array_map($this->field(...), $this->sourceProviders->configurationFields($type)),
                'supports_connection_test' => $this->sourceProviders->capabilities($type)->supportsConnectionTest,
                'schema' => $this->schemaFields('source', $type),
            ];
        }

        return $types;
    }

    /**
     * @param  list<string>  $extra
     * @return list<array<string, mixed>>
     */
    private function destinationTypes(array $extra): array
    {
        $offered = $this->destinationProviders->options();
        $types = [];

        foreach (array_unique([...array_keys($offered), ...$extra]) as $type) {
            if (! $this->destinationProviders->has($type)) {
                continue;
            }

            $capabilities = $this->destinationProviders->capabilities($type);

            $types[] = [
                'type' => $type,
                'label' => $this->destinationProviders->get($type)->label(),
                'offered' => array_key_exists($type, $offered),
                'fields' => array_map($this->field(...), $this->destinationProviders->configurationFields($type)),
                'credential_fields' => array_map($this->field(...), $this->destinationProviders->credentialFields($type)),
                'supports_connection_test' => $capabilities->supportsConnectionTest,
                'supports_media' => $capabilities->supportsMedia,
                'max_text_length' => $capabilities->maxTextLength,
                'schema' => $this->schemaFields('destination', $type),
            ];
        }

        return $types;
    }

    /**
     * @return array<string, mixed>
     */
    private function field(ConfigurationField $field): array
    {
        return [
            'name' => $field->name,
            'label' => $field->label,
            'type' => $field->type->value,
            'hint' => $field->hint,
            'options' => $field->options,
            'placeholder' => $field->placeholder,
            'required' => $field->required,
            'optional' => $field->optional,
        ];
    }

    /**
     * What the in-editor settings panel edits: the same fields as the
     * settings page, posted to the same endpoint.
     *
     * @return array<string, mixed>
     */
    private function settings(Project $project): array
    {
        return [
            'name' => $project->name,
            'description' => $project->description,
            'schedule' => $project->schedule ?? ['frequency' => ScheduleFrequency::Daily->value, 'time' => '09:00'],
            'timezone' => $project->timezone,
            'organization_timezone' => $project->organization->timezone,
            'frequencies' => ScheduleFrequency::options(),
            'weekdays' => ScheduleFrequency::weekdayOptions(),
            'timezones' => DateTimeZone::listIdentifiers(),
        ];
    }
}
