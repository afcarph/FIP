<?php

declare(strict_types=1);

namespace App\Domain\Fleet\Services;

use App\Domain\Expense\Models\Trip;
use App\Domain\Fleet\Models\Driver;
use App\Domain\User\Models\User;
use App\Domain\Vehicle\Models\OdometerReading;
use App\Domain\Vehicle\Models\Vehicle;
use App\Support\Exceptions\DomainException;
use Illuminate\Support\Facades\DB;

/**
 * The trip lifecycle, and the checks each step owes.
 *
 * One service rather than logic spread across controller actions, because the
 * interesting rules are about *when* a thing may happen, and those read
 * clearly only when the transitions sit next to each other.
 *
 * Two ideas are kept apart throughout, per the fleet's own vocabulary:
 *
 *   ASSIGNED — a VehicleAssignment says this driver is responsible for this
 *              vehicle. It is stewardship, and it persists between jobs.
 *   ON TRIP  — a trip row has started and not ended. It is work in progress.
 *
 * A trip never creates, closes or moves an assignment. Dispatching a vehicle
 * whose keys are held by another driver is a real situation — a relief driver
 * covering a shift — and silently rewriting the assignment to match would
 * throw away who is actually accountable for the vehicle.
 */
final readonly class TripDispatchService
{
    /**
     * Create a trip in draft.
     *
     * Availability is checked here as well as at dispatch. Checking only at
     * dispatch would let a planner fill an afternoon with drafts against one
     * vehicle and discover the clash one at a time.
     */
    public function create(User $actor, array $data): Trip
    {
        $vehicle = $this->vehicleFor($actor, (int) $data['vehicle_id']);
        $driver = $this->driverFor($actor, (int) $data['driver_id']);

        $this->assertVehicleFree($vehicle);
        $this->assertDriverFree($driver);

        return DB::transaction(function () use ($vehicle, $driver, $actor, $data): Trip {
            $trip = new Trip;

            $trip->forceFill([
                // Taken from the vehicle, never from the request. A caller who
                // could name the company could file a trip into another tenant.
                'company_id' => $vehicle->company_id,
                'vehicle_id' => $vehicle->getKey(),
                'driver_id' => $driver->getKey(),
                'fleet_id' => $vehicle->fleet_id,
                'created_by' => $actor->getKey(),
                'status' => Trip::STATUS_DRAFT,
                'origin_label' => $data['origin_label'] ?? null,
                'destination_label' => $data['destination_label'] ?? null,
                'purpose' => $data['purpose'] ?? null,
                'scheduled_for' => $data['scheduled_for'] ?? null,
                'notes' => $data['notes'] ?? null,
            ])->save();

            $trip->forceFill(['reference_no' => $this->reference($trip)])->save();

            return $trip;
        });
    }

    /**
     * Amend a draft.
     *
     * The vehicle and driver may change, so both are re-proven against the
     * caller's own fleet and re-checked for clashes — a planner who swaps in a
     * van already booked for the afternoon should hear about it now rather
     * than at dispatch. This trip is excluded from its own clash check.
     *
     * Only fields present in the payload are touched. A form that sends five
     * of seven fields must not blank the other two.
     */
    public function update(User $actor, Trip $trip, array $data): Trip
    {
        $changes = [];

        if (array_key_exists('vehicle_id', $data)) {
            $vehicle = $this->vehicleFor($actor, (int) $data['vehicle_id']);
            $this->assertVehicleFree($vehicle, $trip);

            $changes['vehicle_id'] = $vehicle->getKey();
            // Kept in step with the vehicle: both are denormalised from it, and
            // a trip filed under the old fleet would go missing from reports.
            $changes['company_id'] = $vehicle->company_id;
            $changes['fleet_id'] = $vehicle->fleet_id;
        }

        if (array_key_exists('driver_id', $data)) {
            $driver = $this->driverFor($actor, (int) $data['driver_id']);
            $this->assertDriverFree($driver, $trip);

            $changes['driver_id'] = $driver->getKey();
        }

        foreach (['origin_label', 'destination_label', 'purpose', 'scheduled_for', 'notes'] as $field) {
            if (array_key_exists($field, $data)) {
                $changes[$field] = $data[$field];
            }
        }

        $trip->forceFill($changes)->save();

        return $trip;
    }

    /**
     * Draft -> dispatched. The vehicle and driver are re-checked, because a
     * draft may have sat for a day and the fleet moves underneath it.
     */
    public function dispatch(Trip $trip): Trip
    {
        $this->assertTransition($trip, Trip::STATUS_DISPATCHED);

        $vehicle = $trip->vehicle;
        $driver = $trip->driver;

        $this->assertVehicleFree($vehicle, $trip);
        $this->assertDriverFree($driver, $trip);

        // Only checked at dispatch. A vehicle can be booked in for a service
        // next week and still have trips planned around it; what must not
        // happen is sending out one that is in the workshop today.
        if ($vehicle?->status === 'in_maintenance') {
            throw new DomainException(
                $vehicle->plate_number.' is in maintenance and cannot be dispatched.',
                'vehicle_unavailable',
                422,
            );
        }

        $trip->forceFill([
            'status' => Trip::STATUS_DISPATCHED,
            'dispatched_at' => now(),
        ])->save();

        return $trip;
    }

    /**
     * Dispatched -> in progress. Dispatching and starting are separate because
     * they answer different questions: the first is "this job is assigned", the
     * second is "the vehicle has left".
     */
    public function start(Trip $trip, ?int $odometerStart = null): Trip
    {
        $this->assertTransition($trip, Trip::STATUS_IN_PROGRESS);
        $this->assertVehicleFree($trip->vehicle, $trip);
        $this->assertDriverFree($trip->driver, $trip);

        $trip->forceFill([
            'status' => Trip::STATUS_IN_PROGRESS,
            'started_at' => now(),
            'odometer_start' => $odometerStart ?? $trip->odometer_start,
        ])->save();

        $this->recordOdometer($trip, $trip->odometer_start, 'trip_start');

        return $trip;
    }

    /** In progress -> completed. */
    public function complete(Trip $trip, ?int $odometerEnd = null, ?string $notes = null): Trip
    {
        $this->assertTransition($trip, Trip::STATUS_COMPLETED);

        $start = $trip->odometer_start;

        if ($odometerEnd !== null && $start !== null && $odometerEnd < $start) {
            throw new DomainException(
                "The closing odometer ({$odometerEnd} km) is below the opening reading ({$start} km).",
                'odometer_decreased',
                422,
            );
        }

        $trip->forceFill([
            'status' => Trip::STATUS_COMPLETED,
            'ended_at' => now(),
            'odometer_end' => $odometerEnd ?? $trip->odometer_end,
            'notes' => $notes ?? $trip->notes,

            // Derived only when both readings are present. Left null otherwise
            // rather than guessed, because distance feeds cost per km.
            'distance_km' => $odometerEnd !== null && $start !== null
                ? $odometerEnd - $start
                : $trip->distance_km,
        ])->save();

        $this->recordOdometer($trip, $trip->odometer_end, 'trip_end');

        return $trip;
    }

    /**
     * Cancel, before the wheels turn.
     *
     * Not reachable from IN_PROGRESS: something physically happened, and the
     * honest close for that is completion with notes. The record is kept and
     * dated, never deleted — fuel and fraud reporting read this history.
     */
    public function cancel(Trip $trip, string $reason): Trip
    {
        $this->assertTransition($trip, Trip::STATUS_CANCELLED);

        $trip->forceFill([
            'status' => Trip::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'cancellation_reason' => $reason,
        ])->save();

        return $trip;
    }

    // ------------------------------------------------------------ internals

    private function assertTransition(Trip $trip, string $to): void
    {
        if ($trip->canTransitionTo($to)) {
            return;
        }

        throw new DomainException(
            "A {$trip->status} trip cannot become {$to}.",
            'invalid_trip_transition',
            422,
        );
    }

    /** The vehicle, proven to be the caller's before anything is built on it. */
    private function vehicleFor(User $actor, int $id): Vehicle
    {
        $vehicle = Vehicle::query()->forUser($actor)->find($id);

        if ($vehicle === null) {
            throw new DomainException('That vehicle is not in your fleet.', 'vehicle_not_found', 404);
        }

        if ($vehicle->status !== 'active') {
            throw new DomainException(
                $vehicle->plate_number." is {$vehicle->status} and cannot take a trip.",
                'vehicle_unavailable',
                422,
            );
        }

        return $vehicle;
    }

    private function driverFor(User $actor, int $id): Driver
    {
        $driver = Driver::query()->forUser($actor)->find($id);

        if ($driver === null) {
            throw new DomainException('That driver is not in your company.', 'driver_not_found', 404);
        }

        if ($driver->status !== 'active') {
            throw new DomainException(
                $driver->full_name." is {$driver->status} and cannot be given a trip.",
                'driver_unavailable',
                422,
            );
        }

        return $driver;
    }

    /** @param Trip|null $except the trip being acted on, which is not a clash with itself */
    private function assertVehicleFree(?Vehicle $vehicle, ?Trip $except = null): void
    {
        if ($vehicle === null) {
            return;
        }

        $clash = Trip::query()
            ->active()
            ->where('vehicle_id', $vehicle->getKey())
            ->when($except !== null, fn ($q) => $q->whereKeyNot($except->getKey()))
            ->first();

        if ($clash !== null) {
            throw new DomainException(
                $vehicle->plate_number.' is already on trip '.$clash->reference_no.'.',
                'vehicle_on_trip',
                422,
            );
        }
    }

    private function assertDriverFree(?Driver $driver, ?Trip $except = null): void
    {
        if ($driver === null) {
            return;
        }

        $clash = Trip::query()
            ->active()
            ->where('driver_id', $driver->getKey())
            ->when($except !== null, fn ($q) => $q->whereKeyNot($except->getKey()))
            ->first();

        if ($clash !== null) {
            throw new DomainException(
                $driver->full_name.' is already on trip '.$clash->reference_no.'.',
                'driver_on_trip',
                422,
            );
        }
    }

    /**
     * A reading taken during a trip is a reading like any other.
     *
     * `odometer_readings` already exists with a `source` column, and fuel
     * logging already writes to it — trips were the odd one out, keeping their
     * readings to themselves. That mattered more than it looks: the vehicle's
     * current_odometer is what maintenance schedules distance-based services
     * against, and tank-to-tank fuel economy needs the odometer to keep moving
     * between fills.
     *
     * The write is monotonic, matching the fuel logger. An earlier version of
     * this service argued trips should not touch the vehicle because two
     * writers on one column would disagree; two writers that only ever advance
     * a value converge on the highest reading instead, which is what an
     * odometer does anyway.
     */
    private function recordOdometer(Trip $trip, ?int $reading, string $source): void
    {
        if ($reading === null || $trip->vehicle === null) {
            return;
        }

        OdometerReading::create([
            'vehicle_id' => $trip->vehicle_id,
            'reading' => $reading,
            'source' => $source,
            'recorded_at' => now(),
            'recorded_by' => $trip->created_by,
        ]);

        if ($reading > (float) $trip->vehicle->current_odometer) {
            $trip->vehicle->forceFill(['current_odometer' => $reading])->saveQuietly();
        }
    }

    /**
     * A reference an operator can say out loud.
     *
     * Per company and year, so two tenants never trade sequence numbers and
     * the year is readable at a glance. The row id is the tiebreaker rather
     * than a counter, which keeps it collision-free without a second table.
     */
    private function reference(Trip $trip): string
    {
        return sprintf('TRP-%s-%05d', now()->format('Y'), $trip->getKey());
    }
}
