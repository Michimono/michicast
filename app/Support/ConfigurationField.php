<?php

namespace App\Support;

use App\Enums\ConfigurationFieldType;

/**
 * One field of a provider's configuration form. The provider declares these
 * so a screen can render its settings without knowing which provider it is.
 * The matching validation lives in the provider's configuration rules.
 */
readonly class ConfigurationField
{
    /**
     * @param  array<string, string>  $options  Choices for a select field, keyed by stored value.
     */
    public function __construct(
        public string $name,
        public string $label,
        public ConfigurationFieldType $type = ConfigurationFieldType::Text,
        public ?string $hint = null,
        public array $options = [],
    ) {}
}
