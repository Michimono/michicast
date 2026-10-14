<?php

namespace App\Support;

use App\Exceptions\UnknownDestinationProviderException;
use Illuminate\Contracts\Container\Container;

/**
 * The only place that maps a destination type string to an implementation.
 * Domain code asks the registry for a provider instead of branching on the
 * type.
 */
class DestinationProviderRegistry
{
    /**
     * @var array<string, class-string<DestinationProvider>>
     */
    private array $providers = [];

    /**
     * @var array<string, DestinationProvider>
     */
    private array $resolved = [];

    public function __construct(private Container $container) {}

    /**
     * @param  class-string<DestinationProvider>  $provider
     */
    public function register(string $type, string $provider): void
    {
        $this->providers[$type] = $provider;

        unset($this->resolved[$type]);
    }

    public function has(string $type): bool
    {
        return isset($this->providers[$type]);
    }

    public function get(string $type): DestinationProvider
    {
        if (! $this->has($type)) {
            throw new UnknownDestinationProviderException("No destination provider is registered for the type [{$type}].");
        }

        return $this->resolved[$type] ??= $this->container->make($this->providers[$type]);
    }

    /**
     * @return list<string>
     */
    public function types(): array
    {
        return array_keys($this->providers);
    }

    /**
     * Types a user may pick for a new destination. Test-only types (Recording)
     * stay registered, so existing destinations keep working, but are only
     * offered when enabled (#202).
     *
     * @return list<string>
     */
    public function selectableTypes(): array
    {
        return array_values(array_filter($this->types(), fn (string $type): bool => $this->isSelectable($type)));
    }

    public function isSelectable(string $type): bool
    {
        if (! $this->has($type)) {
            return false;
        }

        return (bool) config('michicast.test_destinations_enabled', false)
            || ! in_array($type, (array) config('michicast.test_destinations', []), true);
    }

    /**
     * The types a destination form offers, keyed by type string.
     *
     * @return array<string, string>
     */
    public function options(): array
    {
        $options = [];

        foreach ($this->selectableTypes() as $type) {
            $options[$type] = $this->get($type)->label();
        }

        return $options;
    }

    public function capabilities(string $type): DestinationProviderCapabilities
    {
        return $this->get($type)->capabilities();
    }

    /**
     * @return array<string, mixed>
     */
    public function configurationRules(string $type): array
    {
        return $this->get($type)->configurationRules();
    }

    /**
     * @return array<string, mixed>
     */
    public function credentialRules(string $type): array
    {
        return $this->get($type)->credentialRules();
    }

    /**
     * @return list<ConfigurationField>
     */
    public function configurationFields(string $type): array
    {
        return $this->get($type)->configurationFields();
    }

    /**
     * @return list<ConfigurationField>
     */
    public function credentialFields(string $type): array
    {
        return $this->get($type)->credentialFields();
    }

    /**
     * The type to show when a form has not been told which one to render.
     */
    public function defaultType(): ?string
    {
        return $this->selectableTypes()[0] ?? null;
    }
}
