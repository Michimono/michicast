<?php

namespace App\Support;

use JsonSerializable;

/**
 * What a provider is told about the destination it is publishing to. The
 * destination model (#25) builds this; until then callers construct it
 * directly. Configuration is editable and visible, credentials are secret,
 * and the two stay apart. Building one registers its credentials with the
 * shared redactor, so anything a provider throws or logs afterwards is
 * stripped of them.
 */
readonly class DestinationDefinition implements JsonSerializable
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
    ) {
        app(SecretRedactor::class)->remember($credentials);
    }

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

    /**
     * Keep credentials out of JSON, including log context normalization.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }
}
