<?php

namespace App\Support;

use App\Enums\AdminAction;
use App\Models\AdminActionLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;
use InvalidArgumentException;

/**
 * Persist one admin_actions row. Call inside the same DB transaction as the
 * mutation so a rolled-back change leaves no audit trail.
 */
class RecordAdminAction
{
    /**
     * Keys whose values must never land in the changes JSON, even redacted.
     *
     * @var list<string>
     */
    private const FORBIDDEN_CHANGE_KEYS = [
        'password',
        'password_confirmation',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'credentials',
        'config',
        'token',
        'secret',
        'api_key',
        'bot_token',
    ];

    public function __construct(private SecretRedactor $secrets) {}

    /**
     * @param  array<string, array{before?: mixed, after?: mixed}|mixed>  $changes
     */
    public function __invoke(
        AdminAction $action,
        Model $subject,
        ?string $reason = null,
        array $changes = [],
    ): AdminActionLog {
        $admin = Auth::user();

        if (! $admin instanceof User) {
            throw new InvalidArgumentException('An authenticated admin is required to record an admin action.');
        }

        return AdminActionLog::query()->create([
            'admin_id' => $admin->getKey(),
            'action' => $action,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'reason' => $reason,
            'changes' => $this->sanitizeChanges($changes),
            'ip_address' => Request::ip(),
        ]);
    }

    /**
     * Drop credential-bearing keys and redact remaining string values.
     *
     * @param  array<array-key, mixed>  $changes
     * @return array<array-key, mixed>|null
     */
    private function sanitizeChanges(array $changes): ?array
    {
        if ($changes === []) {
            return null;
        }

        return $this->scrub($changes);
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private function scrub(array $values): array
    {
        $clean = [];

        foreach ($values as $key => $value) {
            if (is_string($key) && $this->isForbiddenKey($key)) {
                continue;
            }

            if (is_array($value)) {
                $clean[$key] = $this->scrub($value);

                continue;
            }

            if (is_string($value)) {
                $clean[$key] = $this->secrets->redact($value);

                continue;
            }

            $clean[$key] = $value;
        }

        return $clean;
    }

    private function isForbiddenKey(string $key): bool
    {
        $normalized = strtolower($key);

        foreach (self::FORBIDDEN_CHANGE_KEYS as $forbidden) {
            if ($normalized === $forbidden || str_ends_with($normalized, '_'.$forbidden)) {
                return true;
            }
        }

        return false;
    }
}
