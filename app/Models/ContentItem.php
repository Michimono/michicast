<?php

namespace App\Models;

use App\Enums\ContentItemLifecycle;
use App\Exceptions\ContentItemIdentityException;
use App\Support\ContentItemHasher;
use Database\Factories\ContentItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Canonical piece of content produced by a source and consumed by destinations.
 *
 * Identity is unique per source: external id when present, otherwise canonical URL.
 * A content-hash change updates the existing row for that identity; it does not
 * create a second row. A new row is only created when the source has never seen
 * that identity.
 *
 * @property ContentItemLifecycle $lifecycle
 * @property list<array{
 *     type?: string|null,
 *     url?: string|null,
 *     mime_type?: string|null,
 *     width?: int|null,
 *     height?: int|null,
 *     alt?: string|null
 * }>|null $media
 * @property array<string, mixed>|null $metadata
 * @property array<string, mixed>|null $raw_payload
 * @property Carbon|null $published_at
 * @property Carbon $discovered_at
 *
 * @phpstan-type ContentItemAttributes array{
 *     project_id: int,
 *     source_id: int,
 *     external_id?: string|null,
 *     canonical_url?: string|null,
 *     title?: string|null,
 *     body?: string|null,
 *     author?: string|null,
 *     published_at?: Carbon|\DateTimeInterface|string|null,
 *     media?: list<array<array-key, mixed>>|null,
 *     metadata?: array<array-key, mixed>|null,
 *     raw_payload?: array<array-key, mixed>|null,
 *     lifecycle?: ContentItemLifecycle|string|null,
 *     discovered_at?: Carbon|\DateTimeInterface|string|null
 * }
 */
#[Fillable([
    'project_id',
    'source_id',
    'external_id',
    'canonical_url',
    'title',
    'body',
    'author',
    'published_at',
    'discovered_at',
    'media',
    'metadata',
    'raw_payload',
    'lifecycle',
])]
class ContentItem extends Model
{
    /** @use HasFactory<ContentItemFactory> */
    use HasFactory;

    private const IDENTITY_KEY_LIMIT = 191;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'lifecycle' => 'discovered',
    ];

    /**
     * Persist by identity. Same identity + same hash is a no-op; same identity +
     * changed hash updates the existing row.
     *
     * @param  ContentItemAttributes  $attributes
     */
    public static function record(array $attributes): self
    {
        return self::normalize($attributes)->persist();
    }

    /**
     * Put a source's raw item into the canonical shape, with its identity and
     * content hash resolved. The item is not saved, so providers can hand back
     * items the caller decides what to do with.
     *
     * @param  ContentItemAttributes  $attributes
     */
    public static function normalize(array $attributes): self
    {
        $externalId = self::nullableString($attributes['external_id'] ?? null);
        $canonicalUrl = self::nullableString($attributes['canonical_url'] ?? null);

        $title = $attributes['title'] ?? null;
        $body = $attributes['body'] ?? null;
        $author = $attributes['author'] ?? null;
        $publishedAt = $attributes['published_at'] ?? null;
        $hasher = new ContentItemHasher;
        $media = $hasher->normalizeMedia($attributes['media'] ?? null);
        if ($media === []) {
            $media = null;
        }

        $item = new self;

        $item->fill([
            'project_id' => $attributes['project_id'],
            'source_id' => $attributes['source_id'],
            'external_id' => $externalId,
            'canonical_url' => $canonicalUrl,
            'title' => $title,
            'body' => $body,
            'author' => $author,
            'published_at' => $publishedAt,
            'media' => $media,
            'metadata' => $attributes['metadata'] ?? null,
            'raw_payload' => $attributes['raw_payload'] ?? null,
        ]);

        $item->identity_key = self::identityKey($externalId, $canonicalUrl);
        $item->content_hash = $hasher->hash([
            'title' => $title,
            'body' => $body,
            'author' => $author,
            'published_at' => $publishedAt,
            'canonical_url' => $canonicalUrl,
            'media' => $media,
        ]);
        $item->discovered_at = Carbon::parse($attributes['discovered_at'] ?? now());
        $item->lifecycle = $attributes['lifecycle'] ?? ContentItemLifecycle::Discovered;

        return $item;
    }

    /**
     * Store this normalized item under its identity. When the source has shown
     * the identity before, the existing row is updated rather than duplicated,
     * and its discovery time and lifecycle are left alone.
     */
    public function persist(): self
    {
        $existing = static::query()
            ->where('source_id', $this->source_id)
            ->where('identity_key', $this->identity_key)
            ->first();

        if ($existing === null) {
            $this->save();

            return $this;
        }

        if ($existing->content_hash === $this->content_hash) {
            return $existing;
        }

        $existing->fill($this->only([
            'project_id',
            'external_id',
            'canonical_url',
            'title',
            'body',
            'author',
            'published_at',
            'media',
            'metadata',
            'raw_payload',
        ]));

        $existing->content_hash = $this->content_hash;
        $existing->save();

        return $existing;
    }

    /**
     * Stable identity for a source: prefer external id, else canonical URL.
     */
    public static function identityKey(?string $externalId, ?string $canonicalUrl): string
    {
        $externalId = self::nullableString($externalId);

        if ($externalId !== null) {
            return self::boundedIdentity('ext', $externalId);
        }

        $canonicalUrl = self::nullableString($canonicalUrl);

        if ($canonicalUrl !== null) {
            return self::boundedIdentity('url', $canonicalUrl);
        }

        throw new ContentItemIdentityException(
            'A content item needs an external id or a canonical URL for identity.',
        );
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'discovered_at' => 'datetime',
            'media' => 'array',
            'metadata' => 'array',
            'raw_payload' => 'array',
            'lifecycle' => ContentItemLifecycle::class,
        ];
    }

    private static function boundedIdentity(string $kind, string $value): string
    {
        $key = $kind.':'.$value;

        if (strlen($key) <= self::IDENTITY_KEY_LIMIT) {
            return $key;
        }

        return $kind.':'.hash('sha256', $value);
    }

    private static function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
