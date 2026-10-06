<?php

namespace App\Http\Requests;

use App\Models\Broadcast;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Evaluates an unsaved mapping against sample data. Nothing is persisted, but
 * the payload is still shaped before it reaches the mapper.
 */
class StoreBroadcastMappingPreviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        $broadcast = $this->route('broadcast');

        if (! $broadcast instanceof Broadcast) {
            return false;
        }

        return $this->user()?->can('preview', $broadcast) ?? false;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'mapping' => ['nullable', 'array'],
            'mapping.version' => ['nullable', 'integer', 'min:1'],
            'mapping.steps' => ['nullable', 'array'],
            'mapping.steps.*.id' => ['nullable', 'string', 'max:64'],
            'mapping.steps.*.operation' => ['required_with:mapping.steps', 'string', 'max:64'],
            'mapping.steps.*.order' => ['nullable', 'integer', 'min:0'],
            'mapping.steps.*.config' => ['nullable', 'array'],
            'mapping.source_provider' => ['nullable', 'string', 'max:64'],
            'mapping.destination_provider' => ['nullable', 'string', 'max:64'],
            'source_data' => ['nullable', 'array'],
            'through_step_id' => ['nullable', 'string', 'max:64'],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function mapping(): ?array
    {
        $mapping = $this->input('mapping');

        return is_array($mapping) ? $mapping : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function sourceData(): ?array
    {
        $data = $this->input('source_data');

        return is_array($data) ? $data : null;
    }

    public function throughStepId(): ?string
    {
        $through = $this->input('through_step_id');

        return is_string($through) && $through !== '' ? $through : null;
    }
}
