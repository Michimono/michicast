<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();
    }

    /**
     * Register the Horizon gate.
     *
     * Dashboard access stays closed until #157 mounts it under /admin.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', fn ($user = null): bool => false);
    }
}
