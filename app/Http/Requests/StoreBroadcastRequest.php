<?php

namespace App\Http\Requests;

use App\Models\Broadcast;
use App\Models\Destination;
use App\Models\Project;
use App\Models\Source;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBroadcastRequest extends FormRequest
{
    /**
     * Checked before validation so a viewer is refused outright.
     */
    public function authorize(): bool
    {
        $project = $this->route('project');

        if (! $project instanceof Project) {
            return false;
        }

        return $this->user()?->can('create', [Broadcast::class, $project]) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $project = $this->route('project');
        $projectId = $project instanceof Project ? $project->getKey() : 0;

        return [
            'name' => ['required', 'string', 'max:255'],
            'source_id' => [
                'required',
                'integer',
                Rule::exists(Source::class, 'id')
                    ->where('project_id', $projectId)
                    ->whereNull('archived_at'),
            ],
            'destination_id' => [
                'required',
                'integer',
                Rule::exists(Destination::class, 'id')
                    ->where('project_id', $projectId)
                    ->whereNull('archived_at'),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'source_id' => 'source',
            'destination_id' => 'destination',
        ];
    }

    public function name(): string
    {
        return $this->string('name')->trim()->toString();
    }

    public function sourceId(): int
    {
        return $this->integer('source_id');
    }

    public function destinationId(): int
    {
        return $this->integer('destination_id');
    }
}
