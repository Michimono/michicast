<?php

namespace App\Http\Requests;

use App\Exceptions\InvalidPipelineGraphException;
use App\Models\Project;
use App\Support\CurrentOrganization;
use App\Support\Pipeline\PipelineGraph;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * The editor posts the whole graph document. Shape and limits are checked
 * here; what the graph means (unconnected nodes, missing records) is the
 * compiler's job and comes back as per-node issues rather than a refusal.
 */
class UpdatePipelineRequest extends FormRequest
{
    private ?PipelineGraph $graph = null;

    public function authorize(CurrentOrganization $current): bool
    {
        $project = $this->route('project');

        if (! $project instanceof Project) {
            return false;
        }

        if ($project->organization()->isNot($current->organization())) {
            abort(404);
        }

        return $this->user()?->can('update', $project) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'graph' => ['required', 'array'],
            'graph.nodes' => ['present', 'array'],
            'graph.edges' => ['present', 'array'],
            'graph.viewport' => ['nullable', 'array'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $graph = $this->input('graph');

                if (! is_array($graph)) {
                    return;
                }

                try {
                    $this->graph = PipelineGraph::fromArray($graph);
                } catch (InvalidPipelineGraphException $e) {
                    foreach ($e->errors as $key => $message) {
                        $validator->errors()->add($key, $message);
                    }
                }
            },
        ];
    }

    /**
     * The parsed graph. Only meaningful after validation passed.
     */
    public function graph(): PipelineGraph
    {
        if ($this->graph === null) {
            /** @var array<string, mixed> $raw */
            $raw = $this->input('graph', []);
            $this->graph = PipelineGraph::fromArray($raw);
        }

        return $this->graph;
    }
}
