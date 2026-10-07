<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Notifications\ResetPasswordNotification;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * @property string|null $two_factor_secret
 * @property list<string>|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property Carbon|null $banned_at
 * @property string|null $ban_reason
 * @property int|null $banned_by
 */
#[Fillable(['name', 'email', 'password', 'timezone'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * @return HasMany<OrganizationMember, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(OrganizationMember::class);
    }

    /**
     * @return BelongsToMany<Organization, $this>
     */
    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class, 'organization_members');
    }

    /**
     * Send the password reset notification on the mail queue.
     */
    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    /**
     * Whether this account is currently banned from signing in.
     */
    public function isBanned(): bool
    {
        return $this->banned_at !== null;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function bannedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'banned_by');
    }

    /**
     * Whether this user's email is listed in PLATFORM_ADMIN_EMAILS.
     *
     * Comparison is case-insensitive. The list is config-only; there is no
     * database flag and registration/profile cannot grant this.
     */
    public function isPlatformAdmin(): bool
    {
        $email = strtolower((string) $this->email);
        $admins = config('michicast.platform_admin_emails', []);

        return is_array($admins) && in_array($email, $admins, true);
    }

    /**
     * Confirmed TOTP enrollment — login must pass the challenge.
     */
    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_secret !== null
            && $this->two_factor_confirmed_at !== null;
    }

    /**
     * Secret stored but not yet confirmed with a valid code.
     */
    public function hasTwoFactorPending(): bool
    {
        return $this->two_factor_secret !== null
            && $this->two_factor_confirmed_at === null;
    }

    /**
     * Clear secret, recovery codes, and confirmation (server recovery / disable).
     */
    public function clearTwoFactorAuthentication(): void
    {
        $this->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();
    }

    /**
     * Consume one recovery code (case-insensitive). Returns false when unknown.
     */
    public function consumeRecoveryCode(string $code): bool
    {
        $codes = $this->two_factor_recovery_codes;

        if (! is_array($codes) || $codes === []) {
            return false;
        }

        $needle = strtolower(trim($code));
        $remaining = [];
        $matched = false;

        foreach ($codes as $stored) {
            if (! $matched && hash_equals(strtolower((string) $stored), $needle)) {
                $matched = true;

                continue;
            }

            $remaining[] = $stored;
        }

        if (! $matched) {
            return false;
        }

        $this->forceFill([
            'two_factor_recovery_codes' => $remaining,
        ])->save();

        return true;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'banned_at' => 'datetime',
        ];
    }
}
