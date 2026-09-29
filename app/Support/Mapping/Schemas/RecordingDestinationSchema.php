<?php

namespace App\Support\Mapping\Schemas;

use App\Support\Mapping\ContentSchema;
use App\Support\Mapping\FieldType;
use App\Support\Mapping\SchemaField;

final class RecordingDestinationSchema
{
    public static function make(): ContentSchema
    {
        return new ContentSchema(
            version: 1,
            providerType: 'recording',
            label: 'Recording destination',
            fields: [
                new SchemaField('text', 'Message text', FieldType::Text, 'Body of the recorded message', true),
                new SchemaField('link', 'Link', FieldType::Url, 'Optional link', false),
                new SchemaField(
                    name: 'media',
                    label: 'Media',
                    type: FieldType::Array,
                    description: 'Optional media list',
                    itemSchema: new SchemaField('item', 'Media item', FieldType::Media),
                ),
            ],
        );
    }
}
