<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Maintenance\Services\MaintenanceService;
use App\Domain\Vehicle\Models\Vehicle;
use Illuminate\Console\Command;

class RefreshMaintenancePredictions extends Command
{
    protected $signature = 'fip:refresh-maintenance {--company= : Restrict to one company}';

    protected $description = 'Recalculate maintenance statuses and refresh AI service predictions';

    public function handle(MaintenanceService $maintenance): int
    {
        $processed = 0;
        $predicted = 0;

        Vehicle::query()
            ->active()
            ->when($this->option('company'), fn ($q, $id) => $q->where('company_id', $id))
            ->chunkById(100, function ($vehicles) use ($maintenance, &$processed, &$predicted): void {
                foreach ($vehicles as $vehicle) {
                    $maintenance->refreshStatuses($vehicle);
                    $processed++;

                    // Predictions need usage history; skip vehicles with none.
                    if ($vehicle->fuelPurchases()->where('purchased_at', '>=', now()->subDays(90))->count() >= 3) {
                        $predicted += count($maintenance->predictUpcoming($vehicle));
                    }
                }
            });

        $this->info("Refreshed {$processed} vehicles, produced {$predicted} predictions.");

        return self::SUCCESS;
    }
}
