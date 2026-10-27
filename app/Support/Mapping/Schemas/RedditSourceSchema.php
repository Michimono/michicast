<?php

namespace App\Support\Mapping\Schemas;

use App\Support\Mapping\ContentSchema;
use App\Support\Mapping\FieldType;
use App\Support\Mapping\SchemaField;

final class RedditSourceSchema
{
    public static function make(): ContentSchema
    {
        return new ContentSchema(
            version: 1,
            providerType: 'reddit',
            label: 'Reddit (public feed)',
            fields: [
                new SchemaField('title', 'Title', FieldType::Text, 'Post title', false),
                new SchemaField('body', 'Body', FieldType::Text, 'Post text; link and image posts add their linked URL', false),
                new SchemaField('link', 'Link', FieldType::Url, 'Canonical Reddit post URL', false),
                new SchemaField('published_at', 'Published', FieldType::Datetime, 'Creation time', false),
                new SchemaField('author', 'Author', FieldType::Text, 'Reddit username', false),
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function sample(): array
    {
        return [
            'title' => 'Weekly game discussion thread',
            'body' => 'Share your decks and questions for this week.',
            'link' => 'https://www.reddit.com/r/pokemon/comments/abc123/weekly_game_discussion_thread/',
            'published_at' => '2026-10-05T12:00:00+00:00',
            'author' => 'mod_account',
        ];
    }
}
