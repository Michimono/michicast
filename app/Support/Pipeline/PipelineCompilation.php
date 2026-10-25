<?php

namespace App\Support\Pipeline;

/**
 * The outcome of compiling a graph: the routes that can run, and whatever
 * kept the rest from compiling.
 */
readonly class PipelineCompilation
{
    /**
     * @param  list<CompiledRoute>  $routes
     * @param  list<PipelineIssue>  $issues
     */
    public function __construct(
        public array $routes,
        public array $issues,
    ) {}

    public function hasErrors(): bool
    {
        foreach ($this->issues as $issue) {
            if ($issue->isError()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{level: string, message: string, node_id: string|null, edge_id: string|null}>
     */
    public function issuesToArray(): array
    {
        return array_map(static fn (PipelineIssue $issue): array => $issue->toArray(), $this->issues);
    }
}
