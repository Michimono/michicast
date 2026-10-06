<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('michicast:clear-two-factor {email : The account email whose 2FA should be cleared}')]
#[Description('Clear TOTP secret and recovery codes for a locked-out user')]
class ClearTwoFactorCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $email = strtolower(trim((string) $this->argument('email')));
        $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();

        if (! $user instanceof User) {
            $this->error('No user found for that email.');

            return self::FAILURE;
        }

        if (! $user->hasTwoFactorEnabled() && ! $user->hasTwoFactorPending()) {
            $this->info('That user has no two-factor authentication to clear.');

            return self::SUCCESS;
        }

        $user->clearTwoFactorAuthentication();
        $this->info('Cleared two-factor authentication for '.$user->email.'.');

        return self::SUCCESS;
    }
}
