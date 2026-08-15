<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Expense\Models\Trip;
use App\Domain\User\Models\User;

/**
 * Who may see and work a trip.
 *
 * Two questions every time, and they are separate: does this trip belong to
 * your tenant, and are you entitled to this kind of action. Route model
 * binding resolves a trip by id without any tenancy scope, so the first check
 * is what stops an id from being an entitlement.
 *
 * Planning and operating are split. `trips.manage` books work and calls it off;
 * `trips.dispatch` sends it out, starts it and closes it. A company manager
 * holds both today, but the split means a dispatcher role can be granted the
 * operational half later without also granting the ability to invent trips.
 */
class TripPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('trips.view');
    }

    public function view(User $user, Trip $trip): bool
    {
        return $this->sharesTenant($user, $trip) && $user->can('trips.view');
    }

    public function create(User $user): bool
    {
        return $user->can('trips.manage');
    }

    public function update(User $user, Trip $trip): bool
    {
        // A finished or abandoned trip is a record, not a form. Editing one
        // would rewrite what happened.
        return ! $trip->isTerminal()
            && $this->sharesTenant($user, $trip)
            && $user->can('trips.manage');
    }

    public function cancel(User $user, Trip $trip): bool
    {
        return $this->sharesTenant($user, $trip) && $user->can('trips.manage');
    }

    public function dispatch(User $user, Trip $trip): bool
    {
        return $this->sharesTenant($user, $trip) && $user->can('trips.dispatch');
    }

    /**
     * A platform administrator has no company, so tenant comparison would
     * exclude them from everything. They are admitted explicitly instead —
     * the same shape the other fleet policies use.
     */
    private function sharesTenant(User $user, Trip $trip): bool
    {
        if ($user->isPlatformAdministrator()) {
            return true;
        }

        return $user->company_id !== null && $user->company_id === $trip->company_id;
    }
}
