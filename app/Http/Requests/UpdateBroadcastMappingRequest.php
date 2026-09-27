<?php

namespace App\Http\Requests;

use App\Models\Broadcast;
use App\Support\Mapping\OperationRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateBroadcastMappingRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Broadcast $broadcast */
        $broadcast = $this->route('broadcast');

        return $this->user()?->can('update', $broadcast) ?? false;
    }

    /**
     * @return array<string, mixed>
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
            'clear' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->boolean('clear')) {
                return;
            }

            $steps = $this->input('mapping.steps');

            if (! is_array($steps)) {
                return;
            }

            $registry = new OperationRegistry;

            foreach ($steps as $index => $step) {
                if (! is_array($step)) {
                    continue;
                }

                $operation = $step['operation'] ?? null;

                if (! is_string($operation) || ! $registry->has($operation)) {
                    $validator->errors()->add(
                        "mapping.steps.{$index}.operation",
                        'Choose a known mapping step.',
                    );
                }
            }
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    public function mapping(): ?array
    {
        if ($this->boolean('clear')) {
            return null;
        }

        $mapping = $this->input('mapping');

        if (! is_array($mapping) || $mapping === []) {
            return null;
        }

        $steps = $mapping['steps'] ?? [];

        if (! is_array($steps) || $steps === []) {
            return null;
        }

        return $mapping;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function sourceData(): ?array
    {
        $data = $this->input('source_data');

        return is_array($data) ? $data : null;
    }
}
