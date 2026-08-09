<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class RouteServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->configureRateLimiting();
    }

    /**
     * Limits are declared in config/fip.php as "requests,minutes".
     *
     * Anonymous traffic is keyed by IP; authenticated traffic by user id, so
     * one noisy office NAT cannot exhaust everyone else's budget.
     */
    private function configureRateLimiting(): void
    {
        // Driven from the config keys rather than a list repeated here. The
        // two had to be edited together, and adding `throttle:location` to a
        // route while the name was missing from this provider failed at
        // request time with "Rate limiter [location] is not defined" — a
        // runtime error for what is really a declaration.
        foreach (array_keys((array) config('fip.rate_limits', [])) as $name) {
            [$attempts, $minutes] = array_pad(
                explode(',', (string) config("fip.rate_limits.{$name}")),
                2,
                1,
            );

            RateLimiter::for($name, static fn (Request $request) => Limit::perMinutes(
                (int) $minutes,
                (int) $attempts,
            )->by($request->user()?->getAuthIdentifier() ?: $request->ip()));
        }
    }
}
