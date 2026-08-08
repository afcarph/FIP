<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\External\AiServiceClient;
use App\Services\External\FcmClient;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Console\ServeCommand;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use PragmaRX\Google2FA\Google2FA;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Google2FA::class, static fn () => new Google2FA);

        $this->app->singleton(AiServiceClient::class, static fn ($app) => new AiServiceClient(
            baseUrl: (string) config('services.ai.base_url'),
            token: config('services.ai.token'),
            timeout: (int) config('services.ai.timeout'),
            retries: (int) config('services.ai.retries'),
        ));

        $this->app->singleton(FcmClient::class, static fn () => new FcmClient(
            serverKey: config('services.fcm.server_key'),
            projectId: config('services.fcm.project_id'),
            endpointTemplate: (string) config('services.fcm.endpoint'),
        ));
    }

    public function boot(): void
    {
        // Models live in App\Domain\<Context>\Models, but factories stay flat in
        // Database\Factories. Laravel's default guess would look for
        // Database\Factories\Domain\<Context>\Models\<Name>Factory, so map on
        // the class basename instead.
        Factory::guessFactoryNamesUsing(
            static fn (string $modelName): string => 'Database\\Factories\\'.class_basename($modelName).'Factory',
        );

        // There is no server-rendered reset page — the API is headless — so the
        // notification must link at the SPA, which posts the token back to
        // POST /api/v1/auth/reset-password. Without this the mailer would try to
        // resolve a `password.reset` route that does not exist here.
        ResetPassword::createUrlUsing(static fn (object $notifiable, string $token): string => sprintf(
            '%s/reset-password?token=%s&email=%s',
            rtrim((string) config('app.frontend_url'), '/'),
            $token,
            urlencode($notifiable->getEmailForPasswordReset()),
        ));

        // Fail loudly in development on lazy loading and mass-assignment slips.
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());
        Model::unguard(false);

        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        $this->passObjectStorageCredentialsToServe();

        // Surface slow queries in the log so regressions are caught early.
        DB::listen(function ($query): void {
            if ($query->time > 500) {
                Log::warning('Slow query', [
                    'sql' => $query->sql,
                    'time_ms' => $query->time,
                    'connection' => $query->connectionName,
                ]);
            }
        });
    }

    /**
     * Let `artisan serve` see the object-storage credentials.
     *
     * ServeCommand forwards a fixed whitelist of environment variables to the
     * PHP built-in server it spawns and blanks everything else, so the
     * credentials Docker Compose supplies never reach the process that actually
     * handles requests. Every S3 write through the local HTTP API therefore
     * failed with `InvalidAccessKeyId`, while the same call from `artisan
     * tinker` — a process that inherits the full environment — succeeded. That
     * split is what made it look like an application bug: receipt scanning and
     * the older price-board scanning both 500'd only over HTTP.
     *
     * Scoped to non-production because nothing else runs `artisan serve`:
     * production is served by PHP-FPM, which has the environment already.
     */
    private function passObjectStorageCredentialsToServe(): void
    {
        if ($this->app->isProduction() || ! class_exists(ServeCommand::class)) {
            return;
        }

        ServeCommand::$passthroughVariables = array_values(array_unique([
            ...ServeCommand::$passthroughVariables,
            'AWS_ACCESS_KEY_ID',
            'AWS_SECRET_ACCESS_KEY',
            'AWS_DEFAULT_REGION',
            'AWS_BUCKET',
            'AWS_ENDPOINT',
            'AWS_USE_PATH_STYLE_ENDPOINT',
            'AWS_URL',
        ]));
    }
}
