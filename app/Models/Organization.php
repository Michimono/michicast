<?php

namespace App\Models;

use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['name', 'slug', 'timezone', 'plan'])]
class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory;

    /**
     * Leaves room in the slug column for the suffix a collision adds.
     */
    private const SLUG_LENGTH_LIMIT = 240;

    /**
     * Build a URL-safe slug for a name, suffixed until it is unused.
     */
    public static function uniqueSlugFrom(string $name): string
    {
        $base = rtrim(Str::limit(Str::slug($name), self::SLUG_LENGTH_LIMIT, ''), '-');

        if ($base === '') {
            $base = 'organization';
        }

        $slug = $base;
        $suffix = 1;

        while (static::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.++$suffix;
        }

        return $slug;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
