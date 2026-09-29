<?php

namespace App\Support\Mapping;

use App\Support\Mapping\Operations\AddText;
use App\Support\Mapping\Operations\Concatenate;
use App\Support\Mapping\Operations\FirstItem;
use App\Support\Mapping\Operations\FormatDate;
use App\Support\Mapping\Operations\JoinItems;
use App\Support\Mapping\Operations\LastItem;
use App\Support\Mapping\Operations\MapField;
use App\Support\Mapping\Operations\MappingOperation;
use App\Support\Mapping\Operations\ReplaceText;
use App\Support\Mapping\Operations\TrimText;
use InvalidArgumentException;

/**
 * Allowlisted mapping operations. Adding a new op means registering it here.
 */
class OperationRegistry
{
    /** @var array<string, MappingOperation> */
    private array $operations = [];

    public function __construct()
    {
        foreach ([
            new MapField,
            new AddText,
            new Concatenate,
            new ReplaceText,
            new TrimText,
            new FormatDate,
            new FirstItem,
            new LastItem,
            new JoinItems,
        ] as $operation) {
            $this->register($operation);
        }
    }

    public function register(MappingOperation $operation): void
    {
        $this->operations[$operation->id()] = $operation;
    }

    public function get(string $id): MappingOperation
    {
        if (! isset($this->operations[$id])) {
            throw new InvalidArgumentException("Unknown mapping operation [{$id}].");
        }

        return $this->operations[$id];
    }

    public function has(string $id): bool
    {
        return isset($this->operations[$id]);
    }

    /**
     * @return list<array{id: string, label: string}>
     */
    public function catalog(): array
    {
        $items = [];

        foreach ($this->operations as $operation) {
            $items[] = [
                'id' => $operation->id(),
                'label' => $operation->label(),
            ];
        }

        return $items;
    }
}
