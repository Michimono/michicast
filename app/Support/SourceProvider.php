<?php

namespace App\Support;

use App\Models\ContentItem;

/**
 * The integration boundary every source type is reached through. Domain code
 * resolves an implementation from the registry by type string and never
 * branches on the type itself.
 */
interface SourceProvider
{
    /**
     * The type string this provider is registered under.
     */
    public function type(): string;

    /**
     * The name the source form shows for this type.
     */
    public function label(): string;

    /**
     * Validation rules for the provider's JSON config, keyed by config field.
     * The source form and the API both validate against these.
     *
     * @return array<string, mixed>
     */
    public function configurationRules(): array;

    /**
     * The fields a source form renders for this provider, in display order.
     * Each one is validated by the matching configuration rule.
     *
     * @return list<ConfigurationField>
     */
    public function configurationFields(): array;

    public function capabilities(): SourceProviderCapabilities;

    /**
     * Report whether the configuration reaches the remote service. Expected
     * failures come back as a result rather than as an exception.
     */
    public function testConnection(SourceDefinition $source): ConnectionTestResult;

    /**
     * The items the source currently offers, normalized and unsaved. The
     * caller persists them, so provider shapes stop here.
     *
     * @return list<ContentItem>
     */
    public function fetch(SourceDefinition $source): array;
}
