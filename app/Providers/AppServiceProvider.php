<?php

namespace App\Providers;

use App\Models\User;
use App\Support\CurrentOrganization;
use App\Support\DestinationProviderRegistry;
use App\Support\DomCssHtmlSelectorParser;
use App\Support\ExecutingProjectRunner;
use App\Support\HtmlSelectorParser;
use App\Support\ProjectRunner;
use App\Support\SecretRedactor;
use App\Support\SourceProviderRegistry;
use Composer\CaBundle\CaBundle;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
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

        $this->app->scoped(SecretRedactor::class);

        $this->app->bind(HtmlSelectorParser::class, DomCssHtmlSelectorParser::class);

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

        Gate::define('accessAdmin', fn (User $user): bool => $user->isPlatformAdmin());

        // Override Pulse's local-env viewPulse bypass so /admin/pulse matches production
        // gating (#158). callAfterResolving runs after Pulse's own gate registration.
        $this->callAfterResolving(GateContract::class, function (GateContract $gate): void {
            $gate->define('viewPulse', fn (?User $user = null): bool => $user instanceof User
                && $gate->forUser($user)->allows('accessAdmin'));
        });

        // Livewire is only used by the Pulse vendor dashboard (#158). Disable
        // auto-injection so normal app pages never ship Livewire assets; Pulse's
        // own layout pulls in @livewireStyles / @livewireScriptConfig. CSP-safe
        // mode avoids needing script-src 'unsafe-eval' on /admin/pulse (nginx map
        // from #157 still allows 'unsafe-inline' for those inline assets).
        config([
            'livewire.inject_assets' => false,
            'livewire.csp_safe' => true,
        ]);

        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)->by($this->emailAndIpKey($request)));

        RateLimiter::for('two-factor', fn (Request $request) => Limit::perMinute(5)->by(
            (string) $request->session()->get('login.id', $request->ip())
        ));

        RateLimiter::for('registration', fn (Request $request) => Limit::perMinute(5)->by((string) $request->ip()));

        RateLimiter::for('password-reset', fn (Request $request) => Limit::perMinute(3)->by($this->emailAndIpKey($request)));

        RateLimiter::for('invitation', fn (Request $request) => $this->actorAndOrganizationLimits($request, 'organization', perActor: 10, perOrganization: 30));

        RateLimiter::for('content-preview', fn (Request $request) => $this->actorAndOrganizationLimits($request, 'broadcast', perActor: 6, perOrganization: 30));

        RateLimiter::for('mapping-preview', fn (Request $request) => $this->actorAndOrganizationLimits($request, 'broadcast', perActor: 60, perOrganization: 300));

        RateLimiter::for('broadcast-dry-run', fn (Request $request) => $this->actorAndOrganizationLimits($request, 'project', perActor: 3, perOrganization: 15));

        RateLimiter::for('source-connection-test', fn (Request $request) => $this->actorAndOrganizationLimits($request, 'source', perActor: 6, perOrganization: 30));

        RateLimiter::for('source-preview', fn (Request $request) => $this->actorAndOrganizationLimits($request, 'project', perActor: 6, perOrganization: 30));

        RateLimiter::for('destination-connection-test', fn (Request $request) => $this->actorAndOrganizationLimits($request, 'destination', perActor: 6, perOrganization: 30));

        RateLimiter::for('admin', fn (Request $request) => Limit::perMinute(30)->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));
    }

    /**
     * Throttle an expensive action twice: per actor and target, so iterating on
     * one record does not spend another's allowance, and per organization, so
     * many members of one tenant cannot flood outbound calls. Each tenant only
     * ever spends its own organization bucket.
     *
     * @return list<Limit>
     */
    private function actorAndOrganizationLimits(Request $request, string $parameter, int $perActor, int $perOrganization): array
    {
        return [
            Limit::perMinute($perActor)->by('actor|'.$this->userAndRouteKey($request, $parameter)),
            Limit::perMinute($perOrganization)->by('organization|'.$this->routeKey($request, 'organization')),
        ];
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
     * Key a fetch-backed action per actor and per target, so previewing one
     * broadcast does not spend another's allowance.
     */
    private function userAndRouteKey(Request $request, string $parameter): string
    {
        return ($request->user()?->getAuthIdentifier() ?? $request->ip())
            .'|'.$this->routeKey($request, $parameter);
    }

    /**
     * The route parameter is already a model once bindings have been substituted.
     */
    private function routeKey(Request $request, string $parameter): string
    {
        $target = $request->route($parameter);

        return (string) ($target instanceof Model ? $target->getKey() : $target);
    }
}
