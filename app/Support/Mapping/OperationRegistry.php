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
use App\Support\Mapping\Operations\SetField;
use App\Support\Mapping\Operations\TrimText;
use InvalidArgumentException;

/**
 * Allowlisted mapping operations. Adding a new op means registering it here.
 * Set field is primary; legacy ops remain for saved mappings.
 */
class OperationRegistry
{
    /** @var array<string, MappingOperation> */
    private array $operations = [];

    public function __construct()
    {
        foreach ([
            new SetField,
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
     * @return list<array{id: string, label: string, primary?: bool, secondary?: bool}>
     */
    public function catalog(): array
    {
        $secondary = ['map', 'add_text', 'concatenate'];
        $items = [];

        foreach ($this->operations as $operation) {
            $item = [
                'id' => $operation->id(),
                'label' => $operation->label(),
            ];

            if ($operation->id() === 'set_field') {
                $item['primary'] = true;
            } elseif (in_array($operation->id(), $secondary, true)) {
                $item['secondary'] = true;
            }

            $items[] = $item;
        }

        return $items;
    }
}
