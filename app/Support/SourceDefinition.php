<?php

namespace App\Support;

/**
 * What a provider is told about the source it is working for. The source model
 * (#24) builds this; until then callers construct it directly.
 */
readonly class SourceDefinition
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        public string $type,
        public array $config,
        public int $projectId,
        public int $sourceId,
    ) {}

    public function config(string $key, mixed $default = null): mixed
    {
        return data_get($this->config, $key, $default);
    }
}
