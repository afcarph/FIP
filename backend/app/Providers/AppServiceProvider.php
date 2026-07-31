<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\External\AiServiceClient;
use App\Services\External\FcmClient;
use Illuminate\Database\Eloquent\Model;
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
        // Fail loudly in development on lazy loading and mass-assignment slips.
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());
        Model::unguard(false);

        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

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
}
