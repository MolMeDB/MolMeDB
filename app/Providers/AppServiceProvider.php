<?php

namespace App\Providers;

use App\Listeners\RunPredictionsMigrationsAfterDefaultMigrate;
use App\Models\Config as ConfigModel;
use App\Models\Filesystem;
use App\Models\SshCredential;
use App\Policies\ConfigPolicy;
use App\Policies\PredictionDatasetPolicy;
use Dedoc\Scramble\Scramble;
use Exception;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Modules\PredictionWorkers\Models\PredictionDataset;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Must run in register(), not boot(): ScrambleServiceProvider reads
        // this flag during its own boot() to decide whether to register its
        // default /docs/api(.json) routes, and register() across all
        // providers always completes before any provider's boot() runs.
        Scramble::ignoreDefaultRoutes();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(ConfigModel::class, ConfigPolicy::class);
        Gate::policy(PredictionDataset::class, PredictionDatasetPolicy::class);

        // dedoc/scramble restricts its rendered docs page to the local
        // environment by default. It documents the open, unauthenticated
        // public v1 API only (see config/scramble.php), so there is nothing
        // to protect — allow it everywhere for FAIR "Accessible" discovery.
        Gate::define('viewApiDocs', fn () => true);

        // Serve the docs/spec under the public v1 path itself instead of
        // Scramble's default /docs/api(.json) (ignored in register(), see
        // above), reusing the public API's own CORS policy (the global
        // HandleCors below is skipped for api/v*).
        Scramble::registerUiRoute(path: 'api/v1/docs')->middleware('public-cors');
        Scramble::registerJsonSpecificationRoute(path: 'api/v1/openapi.json')->middleware('public-cors');

        // The public API sets its own open CORS policy (App\Http\Middleware\PublicApiCors).
        // The global HandleCors middleware runs as the outermost layer and would
        // otherwise still attach config/cors.php's credentialed-frontend headers
        // (e.g. Access-Control-Allow-Credentials) on top of it — skip it entirely here.
        HandleCors::skipWhen(fn (Request $request): bool => preg_match('#^api/(v\d+|rdf)(/|$)#', $request->path()) === 1);

        RateLimiter::for('remote-prediction-status', fn (): Limit => Limit::perMinute(
            max(1, (int) config('prediction-workers.remote.worker.max_status_requests_per_minute', 30)),
        )->by('remote-prediction-status'));

        RateLimiter::for('resend-email-verification', function (Request $request): Limit {
            $email = Str::lower((string) $request->input('email'));

            return Limit::perMinute(1)->by($email !== '' ? $email : $request->ip());
        });

        RateLimiter::for('public-api', function (Request $request): array {
            return [
                Limit::perMinute(60)->by('minute:'.$request->ip()),
                Limit::perDay(5000)->by('day:'.$request->ip()),
            ];
        });

        // Stacks on top of the blanket 'public-api' limiter (applied to the whole
        // public v1 route group) — only adds extra restriction when a substructure
        // search (expensive Bingo query) is actually requested.
        RateLimiter::for('public-api-substructure', function (Request $request): array {
            if (! filled($request->query('substructure'))) {
                return [];
            }

            return [
                Limit::perMinute(6)->by('substructure-minute:'.$request->ip()),
                Limit::perHour(30)->by('substructure-hour:'.$request->ip()),
            ];
        });

        // Stacks on top of 'public-api' like the substructure limiter: a
        // similarity search at the lowest threshold (0.7) scores up to
        // thousands of structures (~0.5 s on production data).
        RateLimiter::for('public-api-similarity', fn (Request $request): array => [
            Limit::perMinute(10)->by('similarity-minute:'.$request->ip()),
            Limit::perHour(60)->by('similarity-hour:'.$request->ip()),
        ]);

        Event::listen(CommandFinished::class, RunPredictionsMigrationsAfterDefaultMigrate::class);

        ResetPassword::createUrlUsing(function (object $notifiable, string $token) {
            return config('app.frontend_url')."/password-reset/{$token}?email=".urlencode($notifiable->getEmailForPasswordReset());
        });

        VerifyEmail::createUrlUsing(function (object $notifiable) {
            $url = URL::temporarySignedRoute(
                'verification.verify',
                now()->addMinutes(config('auth.verification.expire', 60)),
                [
                    'id' => $notifiable->getKey(),
                    'hash' => sha1($notifiable->getEmailForVerification()),
                ],
                absolute: false,
            );

            $query = parse_url($url, PHP_URL_QUERY);

            return config('app.frontend_url')."/verify-email/{$notifiable->getKey()}/".sha1($notifiable->getEmailForVerification()).($query ? "?{$query}" : '');
        });

        if ($this->app->environment('production')) {
            URL::forceRootUrl(config('app.url'));
            URL::forceScheme('https');
        }

        $this->app->booted(function () {
            $this->registerDynamicServices();
        });
    }

    public function registerDynamicServices(): void
    {
        if ($this->isTestRuntime()) {
            return;
        }

        try {
            if (! Schema::hasTable('filesystems')) {
                return;
            }
        } catch (Exception) {
            return;
        }

        /** @var Filesystem[] $filesystems */
        $filesystems = Filesystem::orderBy('scope_id', 'desc')->get();

        $invalid = [];

        foreach ($filesystems as $filesystem) {
            if ($filesystem->type < 0) {
                // Do not register default drivers
                continue;
            }

            if (! $filesystem->isConfigured()) {
                $invalid[] = $filesystem->id;

                continue;
            }

            if (in_array($filesystem->scope_id, $invalid)) {
                $invalid[] = $filesystem->id;

                continue;
            }

            if (! $filesystem->scope()->exists()) {
                Config::set('filesystems.disks.'.$filesystem->systemName, [
                    'driver' => $filesystem->driver,
                    'host' => $filesystem->host,
                    'port' => $filesystem->port,
                    'username' => $filesystem->sshCredential->username,
                    'password' => $filesystem->sshCredential->type == SshCredential::AUTH_TYPE_PASSWORD ? $filesystem->sshCredential->password : null,
                    'privateKey' => $filesystem->sshCredential->type == SshCredential::AUTH_TYPE_KEY ? $filesystem->sshCredential->private_key : null,
                    'passphrase' => $filesystem->sshCredential->type == SshCredential::AUTH_TYPE_KEY ? $filesystem->sshCredential->passphrase : null,
                    'visibility' => 'public',
                    'directoryPerm' => '0755',
                    'permPublic' => '0644',
                    'permPrivate' => '0644',
                    'root' => $filesystem->root_path,
                    'timeout' => 30,
                ]);
            } else {
                Config::set('filesystems.disks.'.$filesystem->systemName, [
                    'driver' => 'scoped',
                    'disk' => $filesystem->scope->systemName,
                    'prefix' => trim($filesystem->root_path, '/'),
                ]);
            }

            Storage::forgetDisk($filesystem->systemName);
        }
    }

    private function isTestRuntime(): bool
    {
        $consoleArguments = $_SERVER['argv'] ?? [];

        if ($this->app->runningUnitTests() || $this->app->environment('testing')) {
            return true;
        }

        if (! $this->app->runningInConsole()) {
            return false;
        }

        return in_array('test', $consoleArguments, true)
            || in_array('phpunit', $consoleArguments, true)
            || in_array('pest', $consoleArguments, true);
    }
}
