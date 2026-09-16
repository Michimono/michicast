<?php

namespace App\Http\Requests\Concerns;

use App\Support\SourceProviderRegistry;

/**
 * Source forms validate against the rules the chosen provider declares, so a
 * new provider needs no change here.
 */
trait ValidatesSourceConfiguration
{
    /**
     * The provider's rules, keyed for the nested `config` input.
     *
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
     * Name the config fields the way the provider labels them, so an error
     * reads "The feed URL field ..." instead of "The config.feed_url field ...".
     *
     * @return array<string, string>
     */
    protected function configurationAttributes(string $type): array
    {
        if (! $this->registry()->has($type)) {
            return [];
        }

        $attributes = [];

        foreach ($this->registry()->configurationFields($type) as $field) {
            $attributes['config.'.$field->name] = mb_strtolower($field->label);
        }

        return $attributes;
    }

    /**
     * Only the fields the provider declares are stored, so anything else
     * submitted never reaches the config column.
     *
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
     * Hooks other than `rules()` are not called through the container, so the
     * registry is resolved here rather than injected.
     */
    private function registry(): SourceProviderRegistry
    {
        return $this->container->make(SourceProviderRegistry::class);
    }
}
