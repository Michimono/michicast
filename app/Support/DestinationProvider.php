<?php

namespace App\Support;

/**
 * The integration boundary every destination type is reached through. Domain
 * code resolves an implementation from the registry by type string and never
 * branches on the type itself.
 */
interface DestinationProvider
{
    /**
     * The type string this provider is registered under.
     */
    public function type(): string;

    /**
     * The name the destination form shows for this type.
     */
    public function label(): string;

    /**
     * Validation rules for the provider's visible JSON config, keyed by field.
     *
     * @return array<string, mixed>
     */
    public function configurationRules(): array;

    /**
     * The fields a destination form renders for visible settings.
     *
     * @return list<ConfigurationField>
     */
    public function configurationFields(): array;

    /**
     * Validation rules for the provider's secrets, kept apart from the config
     * rules because the two are stored and shown differently.
     *
     * @return array<string, mixed>
     */
    public function credentialRules(): array;

    /**
     * The secret fields a destination form asks for. Values are never shown
     * again after save; an empty edit keeps the stored secret.
     *
     * @return list<ConfigurationField>
     */
    public function credentialFields(): array;

    public function capabilities(): DestinationProviderCapabilities;

    /**
     * Report whether the credentials reach the remote service. Expected
     * failures come back as a result rather than as an exception.
     */
    public function testConnection(DestinationDefinition $destination): ConnectionTestResult;

    /**
     * Publish one prepared message. A rejection or a timeout is reported in
     * the result, so only genuinely unexpected faults throw.
     */
    public function publish(DestinationDefinition $destination, PreparedMessage $message): DeliveryResult;
}
