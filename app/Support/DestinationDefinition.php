<?php

namespace App\Support;

/**
 * What a provider is told about the destination it is publishing to. The
 * destination model (#25) builds this; until then callers construct it
 * directly. Configuration is editable and visible, credentials are secret,
 * and the two stay apart.
 */
readonly class DestinationDefinition
{
    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $credentials
     */
    public function __construct(
        public string $type,
        public array $config,
        public array $credentials,
        public int $projectId,
        public int $destinationId,
    ) {}

    public function config(string $key, mixed $default = null): mixed
    {
        return data_get($this->config, $key, $default);
    }

    public function credential(string $key, mixed $default = null): mixed
    {
        return data_get($this->credentials, $key, $default);
    }

    /**
     * Keep credentials out of dumps, logs and stack traces.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'type' => $this->type,
            'config' => $this->config,
            'credentials' => '[redacted]',
            'projectId' => $this->projectId,
            'destinationId' => $this->destinationId,
        ];
    }
}
