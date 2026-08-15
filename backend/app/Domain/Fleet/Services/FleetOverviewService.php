<?php

declare(strict_types=1);

namespace App\Domain\Fleet\Services;

use App\Domain\Expense\Models\Trip;
use App\Domain\Maintenance\Models\MaintenanceSchedule;
use App\Domain\Vehicle\Models\Vehicle;
use App\Domain\Vehicle\Models\VehicleAssignment;
use Illuminate\Support\Collection;

/**
 * The operational picture a fleet opens the product to see.
 *
 * Answers one question — "what is happening with my fleet right now" — and
 * deliberately nothing else. No user counts, no subscription state, no service
 * health: those are administration, and putting them here is what makes a
 * fleet product read as an IT console.
 *
 * Composed alongside DashboardService::forFleet rather than folded into it.
 * That method is already consumed by the fleet page, and its existing keys are
 * a contract; this adds to the payload without touching any of them.
 *
 * Every query is scoped by company_id, taken from the authenticated caller by
 * the controller and never from a request parameter.
 */
class FleetOverviewService
{
    /** Rows in the status table and the attention lists. Enough to scan, not to browse. */
    private const LIST_LIMIT = 10;

    /**
     * @return array<string, mixed>
     */
    public function forCompany(int $companyId, ?int $fleetId = null): array
    {
        $vehicles = $this->vehicles($companyId, $fleetId);

        return [
            'summary' => $this->summary($vehicles),
            'vehicles' => $this->statusRows($vehicles),
            'maintenance' => $this->maintenanceDue($companyId, $fleetId),
            'recent_activity' => $this->recentActivity($companyId, $fleetId),
        ];
    }

    /**
     * The fleet, with the two relations the status table needs.
     *
     * Loaded once and reused for both the counts and the rows: counting in SQL
     * and then listing separately would run the same scan twice and could
     * disagree with itself if a vehicle changed between the two.
     *
     * @return Collection<int, Vehicle>
     */
    private function vehicles(int $companyId, ?int $fleetId): Collection
    {
        return Vehicle::query()
            ->where('company_id', $companyId)
            ->when($fleetId !== null, fn ($q) => $q->where('fleet_id', $fleetId))
            ->with(['currentAssignment.driver:id,first_name,last_name'])
            ->withCount(['trips as open_trips_count' => fn ($q) => $q
                ->whereNotNull('started_at')
                ->whereNull('ended_at')
                ->where('status', '!=', 'completed')])
            ->orderBy('plate_number')
            ->get();
    }

    /**
     * @param Collection<int, Vehicle> $vehicles
     * @return array<string, int>
     */
    private function summary(Collection $vehicles): array
    {
        $onTrip = $vehicles->filter(fn (Vehicle $v) => $this->isOnTrip($v))->count();
        $maintenance = $vehicles->where('status', 'in_maintenance')->count();

        return [
            'total_vehicles' => $vehicles->count(),

            // Available is what is left after the vehicles that cannot take
            // work: out for maintenance, already on a trip, or not active at
            // all. Derived rather than stored, because nothing in the schema
            // records availability and inventing a column would let it drift
            // out of step with the trips and statuses that actually decide it.
            'available' => $vehicles
                ->filter(fn (Vehicle $v) => $v->status === 'active' && ! $this->isOnTrip($v))
                ->count(),

            'on_trip' => $onTrip,
            'maintenance' => $maintenance,
        ];
    }

    /**
     * A trip that has started and not finished.
     *
     * The trips table defines only `completed` as a status anywhere in the
     * codebase, so the timestamps carry the meaning: started, not ended. The
     * status check is belt-and-braces for a row completed without its
     * ended_at being written.
     */
    private function isOnTrip(Vehicle $vehicle): bool
    {
        return (int) ($vehicle->open_trips_count ?? 0) > 0;
    }

    /**
     * @param Collection<int, Vehicle> $vehicles
     * @return list<array<string, mixed>>
     */
    private function statusRows(Collection $vehicles): array
    {
        return $vehicles->take(self::LIST_LIMIT)->map(function (Vehicle $v): array {
            $driver = $v->currentAssignment?->driver;

            return [
                'id' => $v->getKey(),
                'plate_number' => $v->plate_number,
                'display_name' => $v->display_name,
                'driver' => $driver ? [
                    'id' => $driver->getKey(),
                    'name' => trim($driver->first_name.' '.$driver->last_name),
                ] : null,
                // The operational state, not the database column: a vehicle
                // out on a job reads as "on trip" even though its stored
                // status is still active.
                'state' => $this->state($v),
                'status' => $v->status,
            ];
        })->values()->all();
    }

    private function state(Vehicle $vehicle): string
    {
        if ($vehicle->status === 'in_maintenance') {
            return 'maintenance';
        }

        if ($this->isOnTrip($vehicle)) {
            return 'on_trip';
        }

        return $vehicle->status === 'active' ? 'available' : 'inactive';
    }

    /**
     * What needs booking in, soonest first.
     *
     * Overdue and due-soon only. A schedule that is merely scheduled is not
     * something anyone needs to see on opening the product.
     *
     * @return list<array<string, mixed>>
     */
    private function maintenanceDue(int $companyId, ?int $fleetId): array
    {
        return MaintenanceSchedule::query()
            ->whereIn('status', [MaintenanceSchedule::STATUS_OVERDUE, MaintenanceSchedule::STATUS_DUE_SOON])
            ->whereIn('vehicle_id', Vehicle::query()
                ->where('company_id', $companyId)
                ->when($fleetId !== null, fn ($q) => $q->where('fleet_id', $fleetId))
                ->select('id'))
            ->with(['vehicle:id,plate_number', 'type:id,name'])
            ->orderByRaw('due_at IS NULL')      // dated items first; undated cannot be ranked
            ->orderBy('due_at')
            ->limit(self::LIST_LIMIT)
            ->get()
            ->map(static fn (MaintenanceSchedule $s): array => [
                'id' => $s->getKey(),
                'vehicle' => $s->vehicle?->plate_number,
                'service' => $s->type->name,
                'due_at' => $s->due_at?->toDateString(),
                'status' => $s->status,
            ])
            ->all();
    }

    /**
     * What has happened lately, merged from the records that already exist.
     *
     * Assignments and completed trips are the two events the schema actually
     * records with a timestamp and an actor. There is no activity log, and
     * inventing one to fill this panel would mean writing rows nobody asked
     * for — so an empty feed here means nothing has happened, not that the
     * feature is missing.
     *
     * @return list<array<string, mixed>>
     */
    private function recentActivity(int $companyId, ?int $fleetId): array
    {
        $vehicleIds = Vehicle::query()
            ->where('company_id', $companyId)
            ->when($fleetId !== null, fn ($q) => $q->where('fleet_id', $fleetId))
            ->pluck('id');

        if ($vehicleIds->isEmpty()) {
            return [];
        }

        $assignments = VehicleAssignment::query()
            ->whereIn('vehicle_id', $vehicleIds)
            ->with(['vehicle:id,plate_number', 'driver:id,first_name,last_name'])
            ->latest('assigned_at')
            ->limit(self::LIST_LIMIT)
            ->get()
            ->map(static function (VehicleAssignment $a): array {
                $who = $a->driver === null ? 'A driver' : $a->driver->first_name;
                $what = $a->vehicle === null ? 'a vehicle' : $a->vehicle->plate_number;

                return [
                    'type' => 'vehicle_assigned',
                    'summary' => trim($who.' assigned to '.$what),
                    'at' => $a->assigned_at->toIso8601String(),
                ];
            });

        $trips = Trip::query()
            ->whereIn('vehicle_id', $vehicleIds)
            ->whereNotNull('ended_at')
            ->with('vehicle:id,plate_number')
            ->latest('ended_at')
            ->limit(self::LIST_LIMIT)
            ->get()
            ->map(static fn (Trip $t): array => [
                'type' => 'trip_completed',
                'summary' => $t->vehicle === null
                    ? 'Trip completed'
                    : 'Trip completed — '.$t->vehicle->plate_number,
                'at' => $t->ended_at?->toIso8601String(),
            ]);

        return $assignments
            ->concat($trips)
            ->filter(fn (array $e) => $e['at'] !== null)
            ->sortByDesc('at')
            ->take(self::LIST_LIMIT)
            ->values()
            ->all();
    }
}
