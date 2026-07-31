<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Station\Models\GasStation;
use App\Domain\User\Models\User;

class GasStationPolicy
{
    public function viewAny(?User $user): bool
    {
        return true;   // the directory is public
    }

    public function view(?User $user, GasStation $station): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->isPlatformAdministrator()
            || $user->hasRole(config('fip.roles.station_admin'));
    }

    public function update(User $user, GasStation $station): bool
    {
        return $user->isPlatformAdministrator() || $this->manages($user, $station);
    }

    public function delete(User $user, GasStation $station): bool
    {
        return $user->isPlatformAdministrator();
    }

    /**
     * Price updates are the station administrator's core action, so they get
     * their own ability rather than riding on generic `update`.
     */
    public function updatePrices(User $user, GasStation $station): bool
    {
        return $user->isPlatformAdministrator()
            || $user->can('prices.moderate')
            || $this->manages($user, $station);
    }

    private function manages(User $user, GasStation $station): bool
    {
        if ($station->managed_by === $user->getKey()) {
            return true;
        }

        return $station->operator_id !== null
            && $station->operator_id === $user->company_id
            && $user->hasAnyRole([config('fip.roles.station_admin'), config('fip.roles.company_manager')]);
    }
}
