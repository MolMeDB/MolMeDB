<?php

use App\Http\Middleware\EnsureEmailIsVerified;
use App\Http\Middleware\NegotiatePublicApiFormat;
use App\Http\Middleware\NormalizeLivewireUploadUrl;
use App\Http\Middleware\PublicApiCors;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Modules\References\EuropePMC\Console\Commands\ValidatePublications;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        __DIR__.'/../modules/References/EuropePMC/Console/Commands', // TODO: Not working
        ValidatePublications::class,
    ])
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->trustProxies(
            at: [
                '127.0.0.1',
                '::1',
                '10.0.0.0/8',
                '172.16.0.0/12',
                '192.168.0.0/16',
            ],
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO
        );
        $middleware->api(prepend: [
            EnsureFrontendRequestsAreStateful::class,
        ]);
        $middleware->web(prepend: [
            NormalizeLivewireUploadUrl::class,
        ]);

        // Fortify's "login" route is disabled, so guests are sent to the
        // frontend sign-in page instead (the admin panel has its own).
        $middleware->redirectGuestsTo(fn (): string => rtrim((string) config('app.frontend_url'), '/').'/login');

        $middleware->alias([
            'verified' => EnsureEmailIsVerified::class,
            'public-cors' => PublicApiCors::class,
            'negotiate-public-api-format' => NegotiatePublicApiFormat::class,
        ]);

        //
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
