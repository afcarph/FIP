<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Fleet\Models\Driver;
use App\Domain\User\Models\User;

/**
 * Who may act on a driver record.
 *
 * A driver belongs to a company, so tenancy is the whole of the rule. The
 * listing is already scoped by Driver::forUser(), but a detail or write route
 * takes an id from the caller, and an id is not an entitlement — without the
 * check here, knowing a number would be enough to rename somebody else's
 * driver or move them onto another company's fleet.
 *
 * `drivers.manage` says what a role may do. This says to whom.
 */
class DriverPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isPlatformAdministrator() || $user->can('drivers.view');
    }

    public function view(User $user, Driver $driver): bool
    {
        return $user->can('drivers.view') && $this->sharesTenant($user, $driver);
    }

    public function create(User $user): bool
    {
        return $user->isPlatformAdministrator() || $user->can('drivers.manage');
    }

    public function update(User $user, Driver $driver): bool
    {
        return $user->can('drivers.manage') && $this->sharesTenant($user, $driver);
    }

    /**
     * A platform administrator operates across tenants; everybody else is
     * confined to their own. A null company fails closed on either side:
     * somebody outside a tenant shares one with nobody, and two records with
     * null companies are not related just because both fields are empty.
     */
    private function sharesTenant(User $user, Driver $driver): bool
    {
        if ($user->isPlatformAdministrator()) {
            return true;
        }

        return $user->company_id !== null && $user->company_id === $driver->company_id;
    }
}
