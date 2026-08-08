<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Ai\Services\FraudDetectionService;
use App\Domain\Expense\Contracts\FraudScreener;
use App\Domain\Fleet\Contracts\FuelAnomalyScreener;
use App\Domain\Fleet\Services\FuelAnomalyDetector;
use App\Domain\Pricing\Repositories\PriceRepository;
use App\Domain\Station\Repositories\GasStationRepository;
use App\Domain\User\Repositories\UserRepository;
use App\Domain\Vehicle\Repositories\VehicleRepository;
use App\Support\Contracts\RepositoryInterface;
use Illuminate\Support\ServiceProvider;

/**
 * Repositories are singletons: they are stateless and their construction is
 * not free (each resolves a model class and its filter allow-lists).
 */
class RepositoryServiceProvider extends ServiceProvider
{
    /** @var array<class-string<RepositoryInterface>> */
    private const REPOSITORIES = [
        UserRepository::class,
        VehicleRepository::class,
        GasStationRepository::class,
        PriceRepository::class,
    ];

    public function register(): void
    {
        foreach (self::REPOSITORIES as $repository) {
            $this->app->singleton($repository);
        }

        // Expense screens fill-ups through its own port; the Ai context supplies
        // the implementation.
        $this->app->bind(FraudScreener::class, FraudDetectionService::class);

        // Fleet screens level readings through its own port, for the same
        // reason: recording a reading should not depend on a detector.
        $this->app->bind(FuelAnomalyScreener::class, FuelAnomalyDetector::class);
    }

    public function provides(): array
    {
        return [...self::REPOSITORIES, FraudScreener::class, FuelAnomalyScreener::class];
    }
}
