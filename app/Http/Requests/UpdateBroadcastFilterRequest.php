<?php

namespace App\Http\Requests;

use App\Enums\BroadcastFilterRuleType;
use App\Models\Broadcast;
use App\Support\BroadcastFilters\BoundedRegex;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateBroadcastFilterRequest extends FormRequest
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
            'rules' => ['nullable', 'array'],
            'rules.*.type' => ['required', 'string', Rule::enum(BroadcastFilterRuleType::class)],
            'rules.*.field' => ['nullable', 'string', Rule::in(array_keys(BroadcastFilterRuleType::fieldOptions()))],
            'rules.*.value' => ['nullable', 'string', 'max:500'],
            'rules.*.hours' => ['nullable', 'integer', 'min:1', 'max:8760'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'rules.*.type' => 'rule type',
            'rules.*.field' => 'field',
            'rules.*.value' => 'value',
            'rules.*.hours' => 'hours',
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                foreach ($this->input('rules', []) as $index => $rule) {
                    if (! is_array($rule)) {
                        continue;
                    }

                    $type = BroadcastFilterRuleType::tryFrom((string) ($rule['type'] ?? ''));

                    if ($type === null) {
                        continue;
                    }

                    $prefix = 'rules.'.$index;

                    if ($type->needsField() && blank($rule['field'] ?? null)) {
                        $validator->errors()->add($prefix.'.field', 'A field is required for this rule.');
                    }

                    if ($type->needsValue() && blank($rule['value'] ?? null)) {
                        $validator->errors()->add($prefix.'.value', 'A value is required for this rule.');
                    }

                    if ($type->needsHours() && (! isset($rule['hours']) || ! is_numeric($rule['hours']) || (int) $rule['hours'] < 1)) {
                        $validator->errors()->add($prefix.'.hours', 'A number of hours is required for this rule.');
                    }

                    if ($type === BroadcastFilterRuleType::TitleMatches
                        && filled($rule['value'] ?? null)
                        && ! BoundedRegex::isValid((string) $rule['value'])) {
                        $validator->errors()->add($prefix.'.value', 'The value is not a valid regular expression.');
                    }
                }
            },
        ];
    }

    /**
     * Normalized filter payload ready to store. Empty rule lists become null.
     *
     * @return array{rules: list<array<string, mixed>>}|null
     */
    public function filter(): ?array
    {
        $rules = [];

        foreach ($this->input('rules', []) as $rule) {
            if (! is_array($rule)) {
                continue;
            }

            $type = BroadcastFilterRuleType::tryFrom((string) ($rule['type'] ?? ''));

            if ($type === null) {
                continue;
            }

            $normalized = ['type' => $type->value];

            if ($type->needsField()) {
                $normalized['field'] = (string) $rule['field'];
            }

            if ($type->needsValue()) {
                $normalized['value'] = trim((string) $rule['value']);
            }

            if ($type->needsHours()) {
                $normalized['hours'] = (int) $rule['hours'];
            }

            $rules[] = $normalized;
        }

        return $rules === [] ? null : ['rules' => $rules];
    }
}
