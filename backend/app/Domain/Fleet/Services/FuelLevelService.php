<?php

declare(strict_types=1);

namespace App\Domain\Fleet\Services;

use App\Domain\Fleet\Contracts\FuelAnomalyScreener;
use App\Domain\Fleet\Models\VehicleFuelReading;
use App\Domain\Vehicle\Models\Vehicle;
use App\Support\Exceptions\DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The single write path for vehicle fuel levels.
 *
 * Everything that can produce a reading goes through `record()`: a driver
 * typing a gauge value, the simulator, and — when there is hardware — a
 * telemetry gateway. No source gets a private door, which is what keeps the
 * derived state consistent and makes the eventual IoT work additive rather
 * than a second pipeline.
 *
 * Two invariants are worth stating because they are easy to get wrong:
 *
 *  - The vehicle's `current_*` columns are a cache of the *newest* reading, not
 *    of the last one written. A batch flushed from an offline device arrives
 *    late and out of order, and applying it blindly would rewind the dashboard
 *    to a stale level.
 *  - `delta_pct` is measured against the chronologically preceding reading, not
 *    the previously inserted row. When a late reading lands between two
 *    existing ones, the successor's delta is restated so the series stays
 *    internally consistent — otherwise the gap it used to span is counted
 *    twice, and Phase 2's drop detection would read one loss as two.
 */
final readonly class FuelLevelService
{
    public function __construct(private FuelAnomalyScreener $anomalies) {}

    /**
     * Record a reading and refresh the vehicle's cached level.
     *
     * Idempotent on (vehicle, recorded_at, source): a replayed push returns the
     * stored reading untouched rather than raising, because a device retrying
     * after a dropped connection is doing the right thing.
     *
     * @param array{fuel_pct: float|int|string, recorded_at?: mixed, source?: string,
     *              fuel_litres?: float|int|null, fuel_purchase_id?: int|null,
     *              recorded_by?: int|null} $data
     */
    public function record(Vehicle $vehicle, array $data): VehicleFuelReading
    {
        $source = $data['source'] ?? VehicleFuelReading::SOURCE_MANUAL;
        $recordedAt = Carbon::parse($data['recorded_at'] ?? now());
        $fuelPct = round((float) $data['fuel_pct'], 2);

        $this->assertValidSource($source);
        $this->assertValidPercentage($fuelPct);
        $this->assertNotInFuture($recordedAt);

        $existing = VehicleFuelReading::query()
            ->where('vehicle_id', $vehicle->getKey())
            ->where('recorded_at', $recordedAt)
            ->where('source', $source)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $reading = DB::transaction(function () use ($vehicle, $data, $source, $recordedAt, $fuelPct): VehicleFuelReading {
            $previous = $this->previousReading($vehicle, $recordedAt);

            $reading = VehicleFuelReading::create([
                'vehicle_id' => $vehicle->getKey(),
                'fuel_pct' => $fuelPct,
                'fuel_litres' => $this->litresFor($vehicle, $fuelPct, $data['fuel_litres'] ?? null),
                'delta_pct' => $previous !== null ? round($fuelPct - $previous->fuel_pct, 2) : null,
                'source' => $source,
                'fuel_purchase_id' => $data['fuel_purchase_id'] ?? null,
                'recorded_by' => $data['recorded_by'] ?? null,
                'recorded_at' => $recordedAt,
            ]);

            $this->restateSuccessor($vehicle, $reading);
            $this->refreshVehicleState($vehicle, $reading);

            return $reading;
        });

        // Screened after the transaction commits, not inside it: an alert is a
        // separate record about a reading that is already a fact, and a
        // detector that failed should not roll back the measurement it was
        // called to examine.
        $this->anomalies->screen($reading);

        return $reading->refresh();
    }

    /**
     * Delete readings from one source and rebuild the affected vehicles' state
     * from whatever genuine history remains.
     *
     * Scoped to a source on purpose: this exists so simulated data can be
     * removed without touching anything a person or a device recorded.
     *
     * @return int readings deleted
     */
    public function purgeBySource(string $source, ?Vehicle $vehicle = null): int
    {
        $this->assertValidSource($source);

        $query = VehicleFuelReading::query()->where('source', $source);

        if ($vehicle !== null) {
            $query->where('vehicle_id', $vehicle->getKey());
        }

        $vehicleIds = (clone $query)->distinct()->pluck('vehicle_id');

        return DB::transaction(function () use ($query, $vehicleIds): int {
            $deleted = $query->delete();

            Vehicle::query()->whereIn('id', $vehicleIds)->get()
                ->each(fn (Vehicle $affected) => $this->rebuildVehicleState($affected));

            return $deleted;
        });
    }

    /**
     * NORMAL / LOW / CRITICAL for a level, or null when nothing is known.
     *
     * Deliberately returns null rather than NORMAL for an unknown level: a
     * vehicle nobody has reported on is not a vehicle that is fine.
     */
    public function statusFor(?float $fuelPct): ?string
    {
        if ($fuelPct === null) {
            return null;
        }

        return match (true) {
            $fuelPct <= (float) config('fip.fuel_level.critical_pct') => 'CRITICAL',
            $fuelPct <= (float) config('fip.fuel_level.low_pct') => 'LOW',
            default => 'NORMAL',
        };
    }

    /** Whether a level is too old to present as the current one. */
    public function isStale(?Carbon $recordedAt): bool
    {
        if ($recordedAt === null) {
            return true;
        }

        return $recordedAt->lt(now()->subMinutes((int) config('fip.fuel_level.stale_after_minutes')));
    }

    // ---------------------------------------------------------- internals ---

    /** The newest reading strictly before the given moment. */
    private function previousReading(Vehicle $vehicle, Carbon $before): ?VehicleFuelReading
    {
        return VehicleFuelReading::query()
            ->where('vehicle_id', $vehicle->getKey())
            ->where('recorded_at', '<', $before)
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Restate the delta of the reading that now follows a late arrival.
     *
     * Only touches the one row immediately after it: every later delta still
     * measures the same pair of readings it always did.
     */
    private function restateSuccessor(Vehicle $vehicle, VehicleFuelReading $reading): void
    {
        $successor = VehicleFuelReading::query()
            ->where('vehicle_id', $vehicle->getKey())
            ->where('recorded_at', '>', $reading->recorded_at)
            ->orderBy('recorded_at')
            ->orderBy('id')
            ->first();

        if ($successor === null) {
            return;
        }

        $successor->forceFill([
            'delta_pct' => round($successor->fuel_pct - $reading->fuel_pct, 2),
        ])->saveQuietly();
    }

    /**
     * Update the vehicle's cached level — but only when this reading is the
     * newest one on record. A late arrival is stored and still counts towards
     * history; it just does not get to speak for the present.
     */
    private function refreshVehicleState(Vehicle $vehicle, VehicleFuelReading $reading): void
    {
        if ($vehicle->fuel_level_at !== null && $reading->recorded_at->lt($vehicle->fuel_level_at)) {
            return;
        }

        $vehicle->forceFill([
            'current_fuel_pct' => $reading->fuel_pct,
            'current_fuel_litres' => $reading->fuel_litres,
            'fuel_level_at' => $reading->recorded_at,
        ])->saveQuietly();
    }

    /** Reset a vehicle's cached level to its newest surviving reading. */
    private function rebuildVehicleState(Vehicle $vehicle): void
    {
        $newest = VehicleFuelReading::query()
            ->where('vehicle_id', $vehicle->getKey())
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->first();

        $vehicle->forceFill([
            'current_fuel_pct' => $newest?->fuel_pct,
            'current_fuel_litres' => $newest?->fuel_litres,
            'fuel_level_at' => $newest?->recorded_at,
        ])->saveQuietly();
    }

    /**
     * Litres in the tank, from the caller when supplied and otherwise derived
     * from the vehicle's capacity. Null when the capacity is unknown — an
     * invented volume would read as a measurement.
     */
    private function litresFor(Vehicle $vehicle, float $fuelPct, float|int|null $explicit): ?float
    {
        if ($explicit !== null) {
            return round((float) $explicit, 2);
        }

        return $vehicle->tank_capacity !== null
            ? round($vehicle->tank_capacity * ($fuelPct / 100), 2)
            : null;
    }

    private function assertValidSource(string $source): void
    {
        if (! in_array($source, VehicleFuelReading::SOURCES, true)) {
            throw new DomainException(
                sprintf('Unknown fuel reading source [%s].', $source),
                'invalid_fuel_source',
                422,
                ['allowed' => VehicleFuelReading::SOURCES],
            );
        }
    }

    private function assertValidPercentage(float $fuelPct): void
    {
        if ($fuelPct < 0 || $fuelPct > 100) {
            throw new DomainException(
                sprintf('Fuel level %.2f%% is outside the range 0–100.', $fuelPct),
                'invalid_fuel_level',
                422,
            );
        }
    }

    private function assertNotInFuture(Carbon $recordedAt): void
    {
        // A minute of slack: phones and gateways drift, and rejecting a reading
        // because a device's clock is thirty seconds fast would drop good data.
        if ($recordedAt->gt(now()->addMinute())) {
            throw new DomainException(
                'A fuel reading cannot be dated in the future.',
                'fuel_reading_in_future',
                422,
            );
        }
    }
}
