<?php

namespace App\Support;

use App\Exceptions\UnknownSourceProviderException;
use Illuminate\Contracts\Container\Container;

/**
 * The only place that maps a source type string to an implementation. Domain
 * code asks the registry for a provider instead of branching on the type.
 */
class SourceProviderRegistry
{
    /**
     * @var array<string, class-string<SourceProvider>>
     */
    private array $providers = [];

    /**
     * @var array<string, SourceProvider>
     */
    private array $resolved = [];

    public function __construct(private Container $container) {}

    /**
     * @param  class-string<SourceProvider>  $provider
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

    public function get(string $type): SourceProvider
    {
        if (! $this->has($type)) {
            throw new UnknownSourceProviderException("No source provider is registered for the type [{$type}].");
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
     * The types a source form offers, keyed by type string.
     *
     * @return array<string, string>
     */
    public function options(): array
    {
        $options = [];

        foreach ($this->types() as $type) {
            $options[$type] = $this->get($type)->label();
        }

        return $options;
    }

    public function capabilities(string $type): SourceProviderCapabilities
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
}
