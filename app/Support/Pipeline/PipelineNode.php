<?php

namespace App\Support\Pipeline;

use App\Enums\PipelineNodeType;

/**
 * One node of a pipeline graph. Position is editor data; config is what the
 * compiler reads: a source or destination id, filter rules, or a template
 * with formatting rules.
 */
readonly class PipelineNode
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        public string $id,
        public PipelineNodeType $type,
        public float $x,
        public float $y,
        public array $config = [],
    ) {}

    public function label(): ?string
    {
        $label = $this->config['label'] ?? null;

        return is_string($label) && trim($label) !== '' ? trim($label) : null;
    }

    public function sourceId(): ?int
    {
        return $this->referenceId('source_id');
    }

    public function destinationId(): ?int
    {
        return $this->referenceId('destination_id');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function rules(): array
    {
        $rules = $this->config['rules'] ?? [];

        if (! is_array($rules)) {
            return [];
        }

        return array_values(array_filter($rules, 'is_array'));
    }

    public function template(): string
    {
        $template = $this->config['template'] ?? '';

        return is_string($template) ? trim($template) : '';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'x' => $this->x,
            'y' => $this->y,
            'config' => $this->config,
        ];
    }

    private function referenceId(string $key): ?int
    {
        $value = $this->config[$key] ?? null;

        if (! is_numeric($value) || (int) $value < 1) {
            return null;
        }

        return (int) $value;
    }
}
