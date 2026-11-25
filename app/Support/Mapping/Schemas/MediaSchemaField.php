<?php

namespace App\Support\Mapping\Schemas;

use App\Support\Mapping\FieldType;
use App\Support\Mapping\SchemaField;

/**
 * The media field every source offers under the same name, so `{{media}}`
 * attaches the origin's pictures and videos whatever the source is.
 */
final class MediaSchemaField
{
    public const NAME = 'media';

    public static function make(string $description): SchemaField
    {
        return new SchemaField(
            name: self::NAME,
            label: 'Media',
            type: FieldType::Array,
            description: $description,
            itemSchema: new SchemaField('item', 'Media item', FieldType::Media),
        );
    }
}
