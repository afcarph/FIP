<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Notification\Models\PriceAlert;
use App\Domain\Notification\Services\NotificationService;
use App\Domain\Station\Models\GasStation;
use App\Support\Concerns\GeoDistance;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Fired whenever a station price changes. Finds every standing alert the new
 * price satisfies and notifies the owner.
 *
 * Two guards keep this from becoming a spam cannon: a per-alert cooldown, and
 * a geographic filter so a user is only told about stations they could
 * realistically reach.
 */
class EvaluatePriceAlerts implements ShouldQueue
{
    use Dispatchable;
    use GeoDistance;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(
        private readonly int $stationId,
        private readonly int $fuelTypeId,
        private readonly float $price,
    ) {}

    public function handle(NotificationService $notifications): void
    {
        $station = GasStation::with('city', 'brand')->find($this->stationId);

        if ($station === null || $station->status !== 'active') {
            return;
        }

        $alerts = PriceAlert::query()
            ->active()
            ->where('fuel_type_id', $this->fuelTypeId)
            ->where(fn ($q) => $q
                ->where('station_id', $this->stationId)
                ->orWhere('city_id', $station->city_id)
                ->orWhere(fn ($inner) => $inner->whereNull('station_id')->whereNull('city_id')))
            ->with('user.preferences', 'fuelType')
            ->cursor();

        foreach ($alerts as $alert) {
            if (! $alert->matches($this->price) || $alert->isCoolingDown()) {
                continue;
            }

            if (! $this->isWithinReach($alert, $station)) {
                continue;
            }

            $notifications->send($alert->user, 'price_alert', 'price', [
                'template' => 'price_alert_hit',
                'variables' => [
                    'station' => $station->name,
                    'fuel' => $alert->fuelType?->name,
                    'price' => number_format($this->price, 2),
                    'distance' => $this->distanceLabel($alert, $station),
                ],
                'data' => [
                    'station_id' => $station->getKey(),
                    'fuel_type_id' => $this->fuelTypeId,
                    'price' => $this->price,
                ],
                'action_url' => "/stations/{$station->slug}",
            ]);

            $alert->forceFill(['last_fired_at' => now()])->save();
        }
    }

    /**
     * A radius alert is only relevant if the station falls inside it. Users
     * who pinned a specific station or city always pass.
     */
    private function isWithinReach(PriceAlert $alert, GasStation $station): bool
    {
        if ($alert->station_id !== null || $alert->city_id !== null) {
            return true;
        }

        $home = $alert->user?->homeCoordinates() ?? null;

        if ($home === null || $alert->radius_km === null) {
            return true;
        }

        return $this->distanceInKilometres($home['lat'], $home['lng'], $station->latitude, $station->longitude)
            <= $alert->radius_km;
    }

    private function distanceLabel(PriceAlert $alert, GasStation $station): string
    {
        $home = $alert->user?->homeCoordinates();

        if ($home === null) {
            return '—';
        }

        return number_format(
            $this->distanceInKilometres($home['lat'], $home['lng'], $station->latitude, $station->longitude),
            1,
        );
    }
}
