<?php

use App\Http\Middleware\AddStrictTransportSecurityHeader;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Caddy terminates TLS and forwards X-Forwarded-* on the Compose network.
        $middleware->trustProxies(at: '*');
        $middleware->append(AddStrictTransportSecurityHeader::class);

        $middleware->redirectGuestsTo(function () {
            if (session()->has('login.id')) {
                return route('two-factor-challenge.create');
            }

            return route('session.create');
        });
        $middleware->redirectUsersTo(fn () => route('dashboard'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Destination credentials must never round-trip as old input after a failed save.
        $exceptions->dontFlash(['credentials']);

        // A throttled fetch reads like the HTML page instead of "Too Many Attempts."
        $exceptions->render(function (ThrottleRequestsException $exception, Request $request) {
            if (! $request->expectsJson()) {
                return null;
            }

            $seconds = (int) ($exception->getHeaders()['Retry-After'] ?? 60);

            return response()->json([
                'message' => 'Too many attempts. Try again in '.$seconds.' '.str('second')->plural($seconds).'.',
                'retry_after' => $seconds,
            ], 429, $exception->getHeaders());
        });

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
