<?php

namespace App\Models;

use App\Enums\Role;
use Database\Factories\OrganizationMemberFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property Role $role
 */
#[Fillable(['user_id', 'role'])]
class OrganizationMember extends Model
{
    /** @use HasFactory<OrganizationMemberFactory> */
    use HasFactory;

    /**
     * Whether this is the only owner left, so demoting it would leave none.
     */
    public function isLastOwner(): bool
    {
        if ($this->role !== Role::Owner) {
            return false;
        }

        return $this->organization->members()
            ->where('role', Role::Owner)
            ->whereKeyNot($this->getKey())
            ->doesntExist();
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => Role::class,
        ];
    }
}
