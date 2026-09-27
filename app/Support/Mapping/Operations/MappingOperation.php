<?php

namespace App\Support\Mapping\Operations;

use App\Support\Mapping\ContentSchema;
use App\Support\Mapping\MappingError;

/**
 * One allowlisted mapping step kind.
 */
interface MappingOperation
{
    public function id(): string;

    public function label(): string;

    /**
     * Short human description for the compact step card.
     *
     * @param  array<string, mixed>  $config
     */
    public function summarize(array $config, ContentSchema $sourceSchema, ContentSchema $destinationSchema): string;

    /**
     * @param  array<string, mixed>  $config
     * @return list<MappingError>
     */
    public function validate(array $config, ContentSchema $sourceSchema, ContentSchema $destinationSchema): array;

    /**
     * Apply the step to the intermediate destination-shaped state.
     *
     * @param  array<string, mixed>  $state
     * @param  array<string, array<string, mixed>>  $sources  context key => source data
     * @param  array<string, mixed>  $config
     * @return array{state: array<string, mixed>, errors: list<MappingError>}
     */
    public function apply(array $state, array $sources, array $config): array;
}
