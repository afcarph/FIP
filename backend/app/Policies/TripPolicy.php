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
        if (! $this->sharesTenant($user, $trip) || ! $user->can('trips.view')) {
            return false;
        }

        // A driver's list is narrowed to their own trips, so reading a
        // colleague's by id would hand back through one endpoint exactly what
        // the other withholds. Whoever plans the work can read all of it.
        return ! $this->isOperatorOnly($user) || $this->isTheirs($user, $trip);
    }

    public function create(User $user): bool
    {
        return $user->can('trips.manage');
    }

    /**
     * Only a draft may be edited.
     *
     * Stricter than "not finished" on purpose. Once a trip is dispatched a
     * driver has been told where they are going, and changing the destination
     * underneath them is how somebody ends up at the wrong place. A finished
     * or abandoned trip is a record rather than a form, and editing one would
     * rewrite what happened.
     *
     * Plans do change after dispatch. The lifecycle already answers that:
     * cancel with a reason and plan again, which leaves two honest records
     * instead of one silently altered.
     */
    public function update(User $user, Trip $trip): bool
    {
        return $trip->status === Trip::STATUS_DRAFT
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
     * Starting and closing a trip, which is not the same as sending it out.
     *
     * Dispatch decides that a job happens; operating it reports what actually
     * did. The driver holding the trip may do the second without being able to
     * do the first — they cannot invent work for themselves, reassign it, or
     * call it off, but the person in the vehicle is the one who knows when it
     * left and when it got back.
     */
    public function operate(User $user, Trip $trip): bool
    {
        if (! $this->sharesTenant($user, $trip)) {
            return false;
        }

        if ($user->can('trips.dispatch')) {
            return true;
        }

        return $this->isTheirs($user, $trip);
    }

    /**
     * Matched on the driver record rather than the name: two people can share
     * a name, and only the id is theirs. A user with no driver record behind
     * the account matches nothing, which fails closed.
     */
    private function isTheirs(User $user, Trip $trip): bool
    {
        return $trip->driver_id !== null
            && $trip->driver_id === $user->driverProfile?->getKey();
    }

    /**
     * A driver sees the trips given to them, and only those.
     *
     * `forUser` scopes to the tenant, which for a driver would be every trip
     * their company runs — a roster of everyone else's work. Anyone without a
     * planning or dispatching grant is narrowed to their own.
     */
    public function isOperatorOnly(User $user): bool
    {
        return ! $user->can('trips.manage') && ! $user->can('trips.dispatch');
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
