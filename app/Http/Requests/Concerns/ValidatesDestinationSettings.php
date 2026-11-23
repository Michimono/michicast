<?php

namespace App\Http\Requests\Concerns;

use App\Support\DestinationProviderRegistry;

/**
 * Destination forms validate against the rules the chosen provider declares.
 * Configuration and credentials stay on separate input namespaces.
 */
trait ValidatesDestinationSettings
{
    /**
     * @return array<string, mixed>
     */
    protected function configurationRules(string $type): array
    {
        if (! $this->registry()->has($type)) {
            return [];
        }

        $rules = [];

        foreach ($this->registry()->configurationRules($type) as $field => $fieldRules) {
            $rules['config.'.$field] = $fieldRules;
        }

        return $rules;
    }

    /**
     * Full credential rules for create. Every secret the provider requires must
     * be submitted the first time.
     *
     * @return array<string, mixed>
     */
    protected function credentialRules(string $type): array
    {
        if (! $this->registry()->has($type)) {
            return [];
        }

        $rules = [];

        foreach ($this->registry()->credentialRules($type) as $field => $fieldRules) {
            $rules['credentials.'.$field] = $fieldRules;
        }

        return $rules;
    }

    /**
     * Soften required credential rules on edit. An empty field means keep the
     * stored secret, so it cannot be required again.
     *
     * @return array<string, mixed>
     */
    protected function optionalCredentialRules(string $type): array
    {
        if (! $this->registry()->has($type)) {
            return [];
        }

        $rules = [];

        foreach ($this->registry()->credentialFields($type) as $field) {
            $rules['credentials.'.$field->name] = ['nullable', 'string'];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    protected function settingAttributes(string $type): array
    {
        if (! $this->registry()->has($type)) {
            return [];
        }

        $attributes = [];

        foreach ($this->registry()->configurationFields($type) as $field) {
            $attributes['config.'.$field->name] = mb_strtolower($field->label);
        }

        foreach ($this->registry()->credentialFields($type) as $field) {
            $attributes['credentials.'.$field->name] = mb_strtolower($field->label);
        }

        return $attributes;
    }

    /**
     * @return array<string, mixed>
     */
    protected function configurationFor(string $type): array
    {
        if (! $this->registry()->has($type)) {
            return [];
        }

        $config = [];

        foreach ($this->registry()->configurationFields($type) as $field) {
            $value = $this->input('config.'.$field->name);

            $config[$field->name] = is_string($value) ? trim($value) : $value;
        }

        return $config;
    }

    /**
     * Merge submitted secrets over the ones already stored. Empty submitted
     * values are ignored so an edit that leaves a field blank keeps the secret.
     *
     * @param  array<string, mixed>  $existing
     * @return array<string, mixed>
     */
    protected function credentialsFor(string $type, array $existing = []): array
    {
        if (! $this->registry()->has($type)) {
            return $existing;
        }

        $credentials = $existing;

        foreach ($this->registry()->credentialFields($type) as $field) {
            $value = $this->input('credentials.'.$field->name);

            if (! is_string($value)) {
                continue;
            }

            $value = trim($value);

            if ($value === '') {
                continue;
            }

            $credentials[$field->name] = $value;
        }

        return $credentials;
    }

    /**
     * Optional send pace. Both rate fields are required together; a blank
     * pair means send immediately. Absent keys are left untouched so a save
     * from the pipeline canvas does not clear a pace set on this form.
     *
     * @return array<string, mixed>
     */
    protected function pacingRules(): array
    {
        return [
            'rate_limit_count' => ['nullable', 'integer', 'min:1', 'max:1000', 'required_with:rate_limit_minutes'],
            'rate_limit_minutes' => ['nullable', 'integer', 'min:1', 'max:10080', 'required_with:rate_limit_count'],
            'max_queue_size' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'rate_limit_count.required_with' => 'Enter how many messages as well as the minutes, or leave both empty.',
            'rate_limit_minutes.required_with' => 'Enter the minutes as well as how many messages, or leave both empty.',
            'rate_limit_count.min' => 'Messages must be at least 1.',
            'rate_limit_minutes.min' => 'Minutes must be at least 1.',
            'max_queue_size.min' => 'Max waiting must be at least 1.',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function pacingAttributeNames(): array
    {
        return [
            'rate_limit_count' => 'messages',
            'rate_limit_minutes' => 'minutes',
            'max_queue_size' => 'max waiting',
        ];
    }

    /**
     * @return array<string, int|null>
     */
    public function pacing(): array
    {
        $pacing = [];

        foreach (['rate_limit_count', 'rate_limit_minutes', 'max_queue_size'] as $field) {
            if (! $this->exists($field)) {
                continue;
            }

            $value = $this->input($field);
            $pacing[$field] = $value === null || $value === '' ? null : (int) $value;
        }

        return $pacing;
    }

    private function registry(): DestinationProviderRegistry
    {
        return $this->container->make(DestinationProviderRegistry::class);
    }
}
