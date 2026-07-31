<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Expense\Models\FuelPurchase;
use App\Domain\Pricing\Models\PriceReport;
use App\Domain\Station\Models\GasStation;
use App\Domain\User\Models\User;
use App\Domain\Vehicle\Models\Vehicle;
use App\Policies\FuelPurchasePolicy;
use App\Policies\GasStationPolicy;
use App\Policies\PriceReportPolicy;
use App\Policies\UserPolicy;
use App\Policies\VehiclePolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

class AuthServiceProvider extends ServiceProvider
{
    protected $policies = [
        User::class => UserPolicy::class,
        Vehicle::class => VehiclePolicy::class,
        GasStation::class => GasStationPolicy::class,
        FuelPurchase::class => FuelPurchasePolicy::class,
        PriceReport::class => PriceReportPolicy::class,
    ];

    public function boot(): void
    {
        $this->registerPolicies();

        // The super administrator bypasses every gate. Placed *before* other
        // checks so a mis-scoped policy can never lock the platform owner out.
        Gate::before(static fn (User $user) => $user->hasRole(config('fip.roles.super_admin')) ? true : null);
    }
}
