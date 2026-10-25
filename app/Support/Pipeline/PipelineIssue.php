<?php

namespace App\Support\Pipeline;

/**
 * Something the compiler could not turn into a route, pinned to the node or
 * edge the editor should highlight. Errors keep paths through that node from
 * compiling; warnings are informational (an unconnected node, for example).
 */
readonly class PipelineIssue
{
    public const ERROR = 'error';

    public const WARNING = 'warning';

    public function __construct(
        public string $level,
        public string $message,
        public ?string $nodeId = null,
        public ?string $edgeId = null,
    ) {}

    public static function error(string $message, ?string $nodeId = null, ?string $edgeId = null): self
    {
        return new self(self::ERROR, $message, $nodeId, $edgeId);
    }

    public static function warning(string $message, ?string $nodeId = null, ?string $edgeId = null): self
    {
        return new self(self::WARNING, $message, $nodeId, $edgeId);
    }

    public function isError(): bool
    {
        return $this->level === self::ERROR;
    }

    /**
     * @return array{level: string, message: string, node_id: string|null, edge_id: string|null}
     */
    public function toArray(): array
    {
        return [
            'level' => $this->level,
            'message' => $this->message,
            'node_id' => $this->nodeId,
            'edge_id' => $this->edgeId,
        ];
    }
}
