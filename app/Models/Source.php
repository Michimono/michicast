<?php

namespace App\Models;

use App\Exceptions\InvalidSourceTransitionException;
use App\Support\SourceDefinition;
use Database\Factories\SourceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One typed input of a project. Everything the provider needs lives in the
 * JSON config, validated by the rules that provider declares.
 *
 * @property array<string, mixed>|null $config
 * @property int $consecutive_failures
 * @property Carbon|null $archived_at
 * @property Carbon|null $last_tested_at
 * @property bool|null $last_test_successful
 */
#[Fillable(['name', 'type', 'config'])]
class Source extends Model
{
    /** @use HasFactory<SourceFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'consecutive_failures' => 0,
    ];

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * Whether later processing should read this source at all.
     */
    public function isRunnable(): bool
    {
        return ! $this->isArchived();
    }

    public function archive(): void
    {
        if ($this->isArchived()) {
            throw new InvalidSourceTransitionException('This source is already archived.');
        }

        $this->archived_at = now();
        $this->save();
    }

    public function restore(): void
    {
        if (! $this->isArchived()) {
            throw new InvalidSourceTransitionException('Only an archived source can be restored.');
        }

        $this->archived_at = null;
        $this->save();
    }

    /**
     * What the provider is told about this source.
     */
    public function toDefinition(): SourceDefinition
    {
        return new SourceDefinition(
            type: $this->type,
            config: $this->config ?? [],
            projectId: (int) $this->project_id,
            sourceId: (int) $this->getKey(),
        );
    }

    public function recordSuccess(): void
    {
        $this->consecutive_failures = 0;
        $this->save();
    }

    public function recordFailure(): void
    {
        $this->consecutive_failures++;
        $this->save();
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
        return $query->notArchived();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'config' => 'array',
            'consecutive_failures' => 'integer',
            'last_test_successful' => 'boolean',
            'last_tested_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }
}
