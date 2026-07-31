<?php

declare(strict_types=1);

namespace App\Domain\Vehicle\Repositories;

use App\Domain\User\Models\User;
use App\Domain\Vehicle\Models\Vehicle;
use App\Support\Repositories\BaseRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

/** @extends BaseRepository<Vehicle> */
class VehicleRepository extends BaseRepository
{
    protected array $filterable = ['company_id', 'fleet_id', 'owner_id', 'status', 'vehicle_type', 'fuel_type_id'];

    protected array $sortable = ['id', 'plate_number', 'current_odometer', 'avg_km_per_litre', 'created_at'];

    protected array $searchable = ['plate_number', 'nickname', 'vin'];

    protected function model(): string
    {
        return Vehicle::class;
    }

    /** Vehicles the caller is allowed to see, honouring tenant boundaries. */
    public function paginateForUser(User $user, int $perPage = 15, array $filters = []): LengthAwarePaginator
    {
        $query = $this->applyFilters($this->query()->forUser($user), $filters);

        return $query->with(['make', 'model', 'fuelType', 'fleet', 'currentAssignment.driver'])
            ->paginate(min($perPage, 100))
            ->withQueryString();
    }

    /** @return Collection<int, Vehicle> */
    public function forFleet(int $fleetId): Collection
    {
        return $this->query()->where('fleet_id', $fleetId)->active()->get();
    }

    public function findByPlate(string $plate): ?Vehicle
    {
        return $this->query()->where('plate_number', strtoupper(trim($plate)))->first();
    }

    /** @return Collection<int, Vehicle> */
    public function withExpiringDocuments(int $days = 30): Collection
    {
        return $this->query()
            ->active()
            ->documentsExpiringWithin($days)
            ->with(['owner', 'company', 'fleet.manager'])
            ->get();
    }

    /** Fleet-level roll-up used by the fleet dashboard cards. */
    public function fleetStatistics(int $companyId, ?int $fleetId = null): array
    {
        $query = $this->query()->where('company_id', $companyId);

        if ($fleetId !== null) {
            $query->where('fleet_id', $fleetId);
        }

        $row = $query->selectRaw('
            COUNT(*) AS total,
            SUM(status = "active") AS active,
            SUM(status = "in_maintenance") AS in_maintenance,
            AVG(avg_km_per_litre) AS avg_efficiency,
            SUM(current_odometer) AS total_odometer
        ')->first();

        return [
            'total' => (int) $row->total,
            'active' => (int) $row->active,
            'in_maintenance' => (int) $row->in_maintenance,
            'avg_efficiency' => $row->avg_efficiency !== null ? round((float) $row->avg_efficiency, 2) : null,
            'total_odometer' => (float) $row->total_odometer,
        ];
    }
}
