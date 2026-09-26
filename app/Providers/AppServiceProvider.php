<?php

namespace App\Providers;

use App\Models\User;
use App\Support\CurrentOrganization;
use App\Support\DestinationProviderRegistry;
use App\Support\ExecutingProjectRunner;
use App\Support\ProjectRunner;
use App\Support\SourceProviderRegistry;
use Composer\CaBundle\CaBundle;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
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
        // Windows PHP builds often ship with empty curl.cainfo / openssl.cafile.
        // Point every Http client at a usable CA store (system when present, else Mozilla).
        Http::globalOptions([
            'verify' => CaBundle::getSystemCaRootBundlePath(),
        ]);

        ResetPassword::createUrlUsing(fn (User $user, string $token): string => route('passwords.edit', [
            'token' => $token,
            'email' => $user->getEmailForPasswordReset(),
        ]));

        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)->by($this->emailAndIpKey($request)));

        RateLimiter::for('password-reset', fn (Request $request) => Limit::perMinute(3)->by($this->emailAndIpKey($request)));

        RateLimiter::for('content-preview', fn (Request $request) => Limit::perMinute(6)
            ->by($this->userAndRouteKey($request, 'broadcast')));

        RateLimiter::for('broadcast-dry-run', fn (Request $request) => Limit::perMinute(3)
            ->by($this->userAndRouteKey($request, 'project')));

        RateLimiter::for('source-connection-test', fn (Request $request) => Limit::perMinute(6)
            ->by($this->userAndRouteKey($request, 'source')));

        RateLimiter::for('source-preview', fn (Request $request) => Limit::perMinute(6)
            ->by($this->userAndRouteKey($request, 'project')));

        RateLimiter::for('destination-connection-test', fn (Request $request) => Limit::perMinute(6)
            ->by($this->userAndRouteKey($request, 'destination')));
    }

    /**
     * Build a rate limiter key that throttles per account without letting one
     * address lock every account behind a shared network out.
     */
    private function emailAndIpKey(Request $request): string
    {
        return Str::transliterate($request->string('email')->lower()->toString().'|'.$request->ip());
    }

    /**
     * Throttle a fetch-backed action per actor and per target, so previewing
     * one broadcast does not spend another's allowance. The route parameter is
     * already a model once bindings have been substituted.
     */
    private function userAndRouteKey(Request $request, string $parameter): string
    {
        $target = $request->route($parameter);

        return ($request->user()?->getAuthIdentifier() ?? $request->ip())
            .'|'.($target instanceof Model ? $target->getKey() : $target);
    }
}
