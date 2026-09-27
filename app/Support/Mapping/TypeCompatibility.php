<?php

namespace App\Support\Mapping;

/**
 * Centralized rules for whether a source field may map onto a destination field.
 */
final class TypeCompatibility
{
    public static function areCompatible(FieldType $source, FieldType $destination): bool
    {
        if ($source === $destination) {
            return true;
        }

        return match ($destination) {
            FieldType::Text => in_array($source, [
                FieldType::Number,
                FieldType::Boolean,
                FieldType::Datetime,
                FieldType::Url,
            ], true),
            FieldType::Url => $source === FieldType::Text,
            FieldType::Datetime => $source === FieldType::Text,
            FieldType::Number => $source === FieldType::Text,
            FieldType::Boolean => $source === FieldType::Text,
            FieldType::Media, FieldType::Object, FieldType::Array => false,
        };
    }

    public static function fieldsAreCompatible(SchemaField $source, SchemaField $destination): bool
    {
        if ($source->type === FieldType::Array && $destination->type === FieldType::Array) {
            if ($source->itemSchema === null || $destination->itemSchema === null) {
                return true;
            }

            return self::fieldsAreCompatible($source->itemSchema, $destination->itemSchema);
        }

        if ($source->type === FieldType::Media && $destination->type === FieldType::Array) {
            $item = $destination->itemSchema;

            return $item !== null && ($item->type === FieldType::Media || self::areCompatible($source->type, $item->type));
        }

        if ($source->type === FieldType::Array && $destination->type === FieldType::Media) {
            $item = $source->itemSchema;

            return $item !== null && ($item->type === FieldType::Media || self::areCompatible($item->type, $destination->type));
        }

        return self::areCompatible($source->type, $destination->type);
    }

    public static function arrayNeedsReduction(FieldType $source, FieldType $destination): bool
    {
        return $source === FieldType::Array && $destination->isScalar();
    }
}
