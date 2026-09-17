<?php

namespace App\Providers;

use App\Models\User;
use App\Support\CurrentOrganization;
use App\Support\DestinationProviderRegistry;
use App\Support\ExecutingProjectRunner;
use App\Support\ProjectRunner;
use App\Support\SourceProviderRegistry;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    public $bindings = [
        ProjectRunner::class => ExecutingProjectRunner::class,
    ];

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(CurrentOrganization::class);

        $this->app->singleton(SourceProviderRegistry::class, function (Application $app): SourceProviderRegistry {
            $registry = new SourceProviderRegistry($app);

            foreach ((array) config('michicast.sources', []) as $type => $provider) {
                $registry->register((string) $type, $provider);
            }

            return $registry;
        });

        $this->app->singleton(DestinationProviderRegistry::class, function (Application $app): DestinationProviderRegistry {
            $registry = new DestinationProviderRegistry($app);

            foreach ((array) config('michicast.destinations', []) as $type => $provider) {
                $registry->register((string) $type, $provider);
            }

            return $registry;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        ResetPassword::createUrlUsing(fn (User $user, string $token): string => route('passwords.edit', [
            'token' => $token,
            'email' => $user->getEmailForPasswordReset(),
        ]));

        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)->by($this->emailAndIpKey($request)));

        RateLimiter::for('password-reset', fn (Request $request) => Limit::perMinute(3)->by($this->emailAndIpKey($request)));
    }

    /**
     * Build a rate limiter key that throttles per account without letting one
     * address lock every account behind a shared network out.
     */
    private function emailAndIpKey(Request $request): string
    {
        return Str::transliterate($request->string('email')->lower()->toString().'|'.$request->ip());
    }
}
