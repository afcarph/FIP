<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\User\Models\User;
use App\Domain\Vehicle\Models\Vehicle;

/**
 * Access rules for vehicles.
 *
 * Three ownership shapes exist and each is handled explicitly:
 *   - a private motorist owns the vehicle outright (`owner_id`);
 *   - a company owns it and staff of that company may act on it;
 *   - a driver is assigned to it and may log fill-ups but not edit the record.
 */
class VehiclePolicy
{
    public function viewAny(User $user): bool
    {
        return true;   // the query itself is tenant-scoped
    }

    public function view(User $user, Vehicle $vehicle): bool
    {
        return $this->hasAccess($user, $vehicle);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole([
            config('fip.roles.super_admin'),
            config('fip.roles.system_admin'),
            config('fip.roles.fleet_manager'),
            config('fip.roles.company_manager'),
            config('fip.roles.user'),
        ]);
    }

    public function update(User $user, Vehicle $vehicle): bool
    {
        if ($user->isPlatformAdministrator()) {
            return true;
        }

        if ($vehicle->owner_id === $user->getKey()) {
            return true;
        }

        if ($vehicle->company_id !== null && $vehicle->company_id === $user->company_id) {
            return $user->hasAnyRole([
                config('fip.roles.fleet_manager'),
                config('fip.roles.company_manager'),
            ]) || $this->isAssignedDriver($user, $vehicle);
        }

        return false;
    }

    public function delete(User $user, Vehicle $vehicle): bool
    {
        if ($user->isPlatformAdministrator()) {
            return true;
        }

        if ($vehicle->owner_id === $user->getKey()) {
            return true;
        }

        // Deleting fleet assets is a manager-only action; drivers cannot.
        return $vehicle->company_id === $user->company_id
            && $user->hasAnyRole([config('fip.roles.fleet_manager'), config('fip.roles.company_manager')]);
    }

    private function hasAccess(User $user, Vehicle $vehicle): bool
    {
        return $user->isPlatformAdministrator()
            || $vehicle->owner_id === $user->getKey()
            || ($vehicle->company_id !== null && $vehicle->company_id === $user->company_id);
    }

    private function isAssignedDriver(User $user, Vehicle $vehicle): bool
    {
        $driverId = $user->driverProfile?->getKey();

        return $driverId !== null
            && $vehicle->assignments()->whereNull('released_at')->where('driver_id', $driverId)->exists();
    }
}
