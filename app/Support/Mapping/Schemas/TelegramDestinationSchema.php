<?php

namespace App\Support\Mapping\Schemas;

use App\Support\Mapping\ContentSchema;
use App\Support\Mapping\FieldType;
use App\Support\Mapping\SchemaField;

/**
 * Logical Telegram input — not the raw Bot API payload.
 */
final class TelegramDestinationSchema
{
    public static function make(): ContentSchema
    {
        return new ContentSchema(
            version: 1,
            providerType: 'telegram',
            label: 'Telegram',
            fields: [
                new SchemaField(
                    name: 'text',
                    label: 'Message text',
                    type: FieldType::Text,
                    description: 'The message body sent to the chat',
                    required: true,
                ),
                new SchemaField(
                    name: 'media',
                    label: 'Media',
                    type: FieldType::Array,
                    description: 'Optional images or files',
                    required: false,
                    itemSchema: new SchemaField('item', 'Media item', FieldType::Media),
                ),
            ],
        );
    }
}
