<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        // Notifications stay off (#157).
    }

    /**
     * Configure Horizon authorization without the local-environment bypass so
     * /admin/horizon stays gated the same way as the rest of /admin.
     */
    protected function authorization(): void
    {
        $this->gate();

        Horizon::auth(fn ($request): bool => Gate::check('viewHorizon', [$request->user()]));
    }

    /**
     * Register the Horizon gate.
     *
     * Defers to accessAdmin (#153/#154). EnsurePlatformAdmin still enforces
     * the 404 + IP allowlist; password confirmation is middleware.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', fn (?User $user = null): bool => $user instanceof User
            && Gate::forUser($user)->allows('accessAdmin'));
    }
}
