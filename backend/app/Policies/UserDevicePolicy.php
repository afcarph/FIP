<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\User\Models\User;
use App\Domain\User\Models\UserDevice;

/**
 * Access rules for registered devices.
 *
 * A device belongs to a person, not to a tenant, so ownership is the default
 * test rather than company scope: a fleet manager runs the vehicles, not their
 * drivers' handsets, and should not be reading the device list of everyone in
 * the company.
 *
 * Revocation is the deliberate exception. A handset that has been lost with a
 * vehicle association on it is a fleet problem, and waiting for the driver to
 * revoke their own device is not a plan — so a manager of the company that owns
 * the associated vehicle may cut it off. They still cannot rename it, re-point
 * it at another vehicle, or read its history.
 */
class UserDevicePolicy
{
    public function viewAny(User $user): bool
    {
        return true;   // the query itself is scoped by UserDevice::forUser()
    }

    public function view(User $user, UserDevice $device): bool
    {
        return $this->owns($user, $device) || $user->isPlatformAdministrator();
    }

    public function update(User $user, UserDevice $device): bool
    {
        return $this->owns($user, $device) || $user->isPlatformAdministrator();
    }

    /**
     * Cutting a device off is broader than editing it: the owner, a platform
     * administrator, or a manager of the company whose vehicle it reports for.
     */
    public function revoke(User $user, UserDevice $device): bool
    {
        if ($this->owns($user, $device) || $user->isPlatformAdministrator()) {
            return true;
        }

        $companyId = $device->vehicle?->company_id;

        return $companyId !== null
            && $companyId === $user->company_id
            && $user->hasAnyRole([
                config('fip.roles.fleet_manager'),
                config('fip.roles.company_manager'),
            ]);
    }

    /**
     * See the operational health of the fleet's devices.
     *
     * This is the one read that crosses from ownership into tenancy, and it is
     * kept as narrow as the need. A vehicle is only visible on the map while
     * the handset in it is awake and charged, so a silent or flat device is an
     * operational fact about the vehicle, not gossip about its driver.
     *
     * What that buys is health, not history. It does not imply `view` on the
     * device, `viewHistory` on its track, or any right to rename it, re-point
     * it or read its push credentials. And the listing is scoped by
     * UserDevice::scopeForFleetHealth to devices attached to one of the
     * company's vehicles — a driver's personal handset is not in it.
     */
    public function viewFleetHealth(User $user): bool
    {
        if ($user->isPlatformAdministrator()) {
            return true;
        }

        return $user->company_id !== null
            && $user->hasAnyRole([
                config('fip.roles.fleet_manager'),
                config('fip.roles.company_manager'),
            ]);
    }

    /**
     * The health of one specific device.
     *
     * Re-checks the tenancy that the listing scope applies, because a detail
     * route takes an id from the caller and a listing does not. Without this,
     * knowing a device id would be enough to read any device's health.
     */
    public function viewHealth(User $user, UserDevice $device): bool
    {
        if ($this->view($user, $device)) {
            return true;
        }

        if (! $this->viewFleetHealth($user)) {
            return false;
        }

        $companyId = $device->vehicle?->company_id;

        return $companyId !== null
            && ($user->isPlatformAdministrator() || $companyId === $user->company_id);
    }

    /**
     * Reading where a device has been is a stricter question than seeing that
     * the device exists, so it carries its own permission rather than being
     * implied by `view`.
     */
    public function viewHistory(User $user, UserDevice $device): bool
    {
        return $this->view($user, $device) && $user->can('devices.location.history');
    }

    private function owns(User $user, UserDevice $device): bool
    {
        return $device->user_id === $user->getKey();
    }
}
