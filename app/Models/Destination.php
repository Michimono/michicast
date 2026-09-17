<?php

namespace App\Models;

use App\Exceptions\InvalidDestinationTransitionException;
use App\Support\DestinationDefinition;
use Database\Factories\DestinationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One typed publish target of a project. Visible settings live in config;
 * secrets live in credentials and are encrypted at rest.
 *
 * @property array<string, mixed>|null $config
 * @property array<string, mixed>|null $credentials
 * @property bool $enabled
 * @property Carbon|null $archived_at
 * @property Carbon|null $last_tested_at
 * @property bool|null $last_test_successful
 */
#[Fillable(['name', 'type', 'config', 'credentials'])]
#[Hidden(['credentials'])]
class Destination extends Model
{
    /** @use HasFactory<DestinationFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'enabled' => true,
    ];

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * Whether later processing should publish to this destination at all.
     */
    public function isRunnable(): bool
    {
        return $this->enabled && ! $this->isArchived();
    }

    public function canEnable(): bool
    {
        return ! $this->enabled && ! $this->isArchived();
    }

    public function canDisable(): bool
    {
        return $this->enabled && ! $this->isArchived();
    }

    public function enable(): void
    {
        if ($this->isArchived()) {
            throw new InvalidDestinationTransitionException(
                'An archived destination cannot be enabled until it is restored.',
            );
        }

        $this->enabled = true;
        $this->save();
    }

    public function disable(): void
    {
        if ($this->isArchived()) {
            throw new InvalidDestinationTransitionException(
                'An archived destination is already out of use.',
            );
        }

        $this->enabled = false;
        $this->save();
    }

    public function archive(): void
    {
        if ($this->isArchived()) {
            throw new InvalidDestinationTransitionException('This destination is already archived.');
        }

        $this->archived_at = now();
        $this->save();
    }

    public function restore(): void
    {
        if (! $this->isArchived()) {
            throw new InvalidDestinationTransitionException('Only an archived destination can be restored.');
        }

        $this->archived_at = null;
        $this->save();
    }

    /**
     * What the provider is told about this destination.
     */
    public function toDefinition(): DestinationDefinition
    {
        return new DestinationDefinition(
            type: $this->type,
            config: $this->config ?? [],
            credentials: $this->credentials ?? [],
            projectId: (int) $this->project_id,
            destinationId: (int) $this->getKey(),
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
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    #[Scope]
    protected function notArchived(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    #[Scope]
    protected function runnable(Builder $query): Builder
    {
        return $query->notArchived()->where('enabled', true);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'config' => 'array',
            'credentials' => 'encrypted:array',
            'enabled' => 'boolean',
            'last_test_successful' => 'boolean',
            'last_tested_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }
}
