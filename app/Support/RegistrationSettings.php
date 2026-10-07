<?php

namespace App\Support;

use App\Enums\RegistrationMode;
use App\Models\OrganizationInvitation;
use App\Models\PlatformSetting;
use App\Models\User;

/**
 * The single read path for who may register. Values stored in
 * platform_settings win; a missing row falls back to config, so fresh
 * installs and tests need no row. Nothing is cached, so an admin change
 * takes effect on the next request.
 */
class RegistrationSettings
{
    public const REFUSAL_CLOSED = 'Registration is currently closed.';

    public const REFUSAL_CAP_REACHED = 'New sign-ups are paused for today. Please try again tomorrow.';

    public const REFUSAL_INVITE_ONLY = 'Registration is by invitation only. Use the email address your invitation was sent to.';

    /**
     * The mode an admin (or MICHICAST_REGISTRATION) chose, ignoring the daily cap.
     */
    public function mode(): RegistrationMode
    {
        $stored = $this->stored(PlatformSetting::REGISTRATION_MODE);

        if ($stored !== null) {
            $mode = RegistrationMode::tryFrom((string) $stored->value);

            if ($mode !== null) {
                return $mode;
            }
        }

        return $this->configuredMode();
    }

    public function configuredMode(): RegistrationMode
    {
        return RegistrationMode::tryFrom((string) config('michicast.registration.mode', 'open'))
            ?? RegistrationMode::Open;
    }

    /**
     * Daily cap on new accounts, or null for no cap. A stored row with an
     * empty value is an explicit "no cap" that overrides config.
     */
    public function dailyCap(): ?int
    {
        $stored = $this->stored(PlatformSetting::REGISTRATION_DAILY_CAP);

        if ($stored !== null) {
            return $this->normalizeCap($stored->value);
        }

        return $this->configuredDailyCap();
    }

    public function configuredDailyCap(): ?int
    {
        return $this->normalizeCap(config('michicast.registration.daily_cap'));
    }

    /**
     * Whether the admin-chosen values come from platform_settings rather than config.
     */
    public function isStored(): bool
    {
        return PlatformSetting::query()
            ->whereIn('key', [PlatformSetting::REGISTRATION_MODE, PlatformSetting::REGISTRATION_DAILY_CAP])
            ->exists();
    }

    /**
     * Accounts created since midnight UTC, matching the quota day.
     */
    public function registrationsToday(): int
    {
        return User::query()
            ->where('created_at', '>=', now('UTC')->startOfDay())
            ->count();
    }

    public function dailyCapReached(): bool
    {
        $cap = $this->dailyCap();

        return $cap !== null && $this->registrationsToday() >= $cap;
    }

    /**
     * The mode visitors experience right now: a reached daily cap behaves as closed.
     */
    public function effectiveMode(): RegistrationMode
    {
        if ($this->dailyCapReached()) {
            return RegistrationMode::Closed;
        }

        return $this->mode();
    }

    /**
     * Whether any visitor could register at all (drives the "create account" links).
     */
    public function acceptsRegistrations(): bool
    {
        return $this->effectiveMode() !== RegistrationMode::Closed;
    }

    /**
     * Whether this email may register right now.
     */
    public function allows(?string $email): bool
    {
        return match ($this->effectiveMode()) {
            RegistrationMode::Open => true,
            RegistrationMode::Closed => false,
            RegistrationMode::InviteOnly => $email !== null && $this->hasPendingInvitation($email),
        };
    }

    /**
     * The message shown when registration is refused. Identical for every
     * uninvited email so the response never reveals who has been invited.
     */
    public function refusalMessage(): string
    {
        if ($this->dailyCapReached()) {
            return self::REFUSAL_CAP_REACHED;
        }

        return match ($this->mode()) {
            RegistrationMode::Closed => self::REFUSAL_CLOSED,
            default => self::REFUSAL_INVITE_ONLY,
        };
    }

    /**
     * Persist both registration settings. Returns the mode row so callers can
     * use it as the audit subject.
     */
    public function update(RegistrationMode $mode, ?int $dailyCap): PlatformSetting
    {
        PlatformSetting::query()->updateOrCreate(
            ['key' => PlatformSetting::REGISTRATION_DAILY_CAP],
            ['value' => $dailyCap === null ? null : (string) $dailyCap],
        );

        return PlatformSetting::query()->updateOrCreate(
            ['key' => PlatformSetting::REGISTRATION_MODE],
            ['value' => $mode->value],
        );
    }

    private function hasPendingInvitation(string $email): bool
    {
        return OrganizationInvitation::query()
            ->pending()
            ->where('email', strtolower(trim($email)))
            ->exists();
    }

    private function stored(string $key): ?PlatformSetting
    {
        return PlatformSetting::query()->where('key', $key)->first();
    }

    private function normalizeCap(mixed $value): ?int
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        $cap = (int) $value;

        return $cap > 0 ? $cap : null;
    }
}
