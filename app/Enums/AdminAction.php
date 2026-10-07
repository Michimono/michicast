<?php

namespace App\Enums;

enum AdminAction: string
{
    case SignedIn = 'signed_in';
    case Banned = 'banned';
    case Unbanned = 'unbanned';
    case Suspended = 'suspended';
    case Reinstated = 'reinstated';
    case PlanChanged = 'plan_changed';
    case QuotaOverrideSet = 'quota_override_set';
    case QuotaOverrideCleared = 'quota_override_cleared';
    case QuotaUsageReset = 'quota_usage_reset';
    case RegistrationSettingsChanged = 'registration_settings_changed';

    public function label(): string
    {
        return match ($this) {
            self::SignedIn => 'Signed in',
            self::Banned => 'Banned',
            self::Unbanned => 'Unbanned',
            self::Suspended => 'Suspended',
            self::Reinstated => 'Reinstated',
            self::PlanChanged => 'Plan changed',
            self::QuotaOverrideSet => 'Quota override set',
            self::QuotaOverrideCleared => 'Quota override cleared',
            self::QuotaUsageReset => 'Quota usage reset',
            self::RegistrationSettingsChanged => 'Registration settings changed',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $action) {
            $options[$action->value] = $action->label();
        }

        return $options;
    }
}
