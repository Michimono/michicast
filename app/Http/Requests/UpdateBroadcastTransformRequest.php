<?php

namespace App\Http\Requests;

use App\Enums\BroadcastTransformRuleType;
use App\Models\Broadcast;
use App\Support\BroadcastFilters\BoundedRegex;
use App\Support\BroadcastTransforms\TemplatePlaceholders;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateBroadcastTransformRequest extends FormRequest
{
    public function authorize(): bool
    {
        $broadcast = $this->route('broadcast');

        if (! $broadcast instanceof Broadcast) {
            return false;
        }

        return $this->user()?->can('update', $broadcast) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'template' => ['nullable', 'string', 'max:5000'],
            'rules' => ['nullable', 'array'],
            'rules.*.type' => ['required', 'string', Rule::enum(BroadcastTransformRuleType::class)],
            'rules.*.value' => ['nullable', 'string', 'max:1000'],
            'rules.*.length' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'rules.*.pattern' => ['nullable', 'string', 'max:500'],
            'rules.*.replacement' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'rules.*.type' => 'rule type',
            'rules.*.value' => 'value',
            'rules.*.length' => 'length',
            'rules.*.pattern' => 'pattern',
            'rules.*.replacement' => 'replacement',
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $template = $this->string('template')->toString();

                if ($template !== '') {
                    $unknown = TemplatePlaceholders::unknownIn($template);

                    if ($unknown !== []) {
                        $validator->errors()->add(
                            'template',
                            'Unknown placeholder: '.implode(', ', array_map(
                                fn (string $name): string => '{{'.$name.'}}',
                                $unknown,
                            )).'.',
                        );
                    }
                }

                foreach ($this->input('rules', []) as $index => $rule) {
                    if (! is_array($rule)) {
                        continue;
                    }

                    $type = BroadcastTransformRuleType::tryFrom((string) ($rule['type'] ?? ''));

                    if ($type === null) {
                        continue;
                    }

                    $prefix = 'rules.'.$index;

                    if ($type->needsLength() && (! isset($rule['length']) || ! is_numeric($rule['length']) || (int) $rule['length'] < 1)) {
                        $validator->errors()->add($prefix.'.length', 'A length is required for this rule.');
                    }

                    if ($type->needsValue() && blank($rule['value'] ?? null)) {
                        $validator->errors()->add($prefix.'.value', 'A value is required for this rule.');
                    }

                    if ($type->needsPattern()) {
                        if (blank($rule['pattern'] ?? null)) {
                            $validator->errors()->add($prefix.'.pattern', 'A pattern is required for this rule.');
                        } elseif (! BoundedRegex::isValid((string) $rule['pattern'])) {
                            $validator->errors()->add($prefix.'.pattern', 'The pattern is not a valid regular expression.');
                        }
                    }
                }
            },
        ];
    }

    /**
     * Normalized transform payload. An empty template and no rules become null.
     *
     * @return array{template?: string, rules?: list<array<string, mixed>>}|null
     */
    public function transform(): ?array
    {
        $template = trim($this->string('template')->toString());
        $rules = [];

        foreach ($this->input('rules', []) as $rule) {
            if (! is_array($rule)) {
                continue;
            }

            $type = BroadcastTransformRuleType::tryFrom((string) ($rule['type'] ?? ''));

            if ($type === null) {
                continue;
            }

            $normalized = ['type' => $type->value];

            if ($type->needsLength()) {
                $normalized['length'] = (int) $rule['length'];
            }

            if ($type->needsValue()) {
                $normalized['value'] = (string) $rule['value'];
            }

            if ($type->needsPattern()) {
                $normalized['pattern'] = (string) $rule['pattern'];
                $normalized['replacement'] = (string) ($rule['replacement'] ?? '');
            }

            $rules[] = $normalized;
        }

        if ($template === '' && $rules === []) {
            return null;
        }

        $payload = [];

        if ($template !== '') {
            $payload['template'] = $template;
        }

        if ($rules !== []) {
            $payload['rules'] = $rules;
        }

        return $payload;
    }
}
