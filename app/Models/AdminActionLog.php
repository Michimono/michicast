<?php

namespace App\Models;

use App\Enums\AdminAction;
use Database\Factories\AdminActionLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use RuntimeException;

/**
 * Append-only record of a platform admin action.
 *
 * @property array<string, mixed>|null $changes
 */
class AdminActionLog extends Model
{
    /** @use HasFactory<AdminActionLogFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $table = 'admin_actions';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'admin_id',
        'action',
        'subject_type',
        'subject_id',
        'reason',
        'changes',
        'ip_address',
    ];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new RuntimeException('Admin actions are append-only.');
        });

        static::deleting(function (): never {
            throw new RuntimeException('Admin actions are append-only.');
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Human label for the subject on the audit page.
     */
    public function subjectLabel(): string
    {
        $subject = $this->subject;

        if ($subject instanceof User) {
            return $subject->name.' ('.$subject->email.')';
        }

        if ($subject instanceof Organization) {
            return $subject->name;
        }

        if ($subject instanceof Model) {
            $name = $subject->getAttribute('name');

            if (is_string($name) && $name !== '') {
                return $name;
            }
        }

        return class_basename((string) $this->subject_type).' #'.$this->subject_id;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action' => AdminAction::class,
            'changes' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
