<?php

namespace App\Support\Mapping;

/**
 * Closed set of field types used by content schemas and mapping ops.
 */
enum FieldType: string
{
    case Text = 'text';
    case Number = 'number';
    case Boolean = 'boolean';
    case Datetime = 'datetime';
    case Url = 'url';
    case Media = 'media';
    case Object = 'object';
    case Array = 'array';

    public function label(): string
    {
        return match ($this) {
            self::Text => 'text',
            self::Number => 'number',
            self::Boolean => 'boolean',
            self::Datetime => 'datetime',
            self::Url => 'url',
            self::Media => 'media',
            self::Object => 'object',
            self::Array => 'array',
        };
    }

    public function isScalar(): bool
    {
        return match ($this) {
            self::Object, self::Array => false,
            default => true,
        };
    }
}
