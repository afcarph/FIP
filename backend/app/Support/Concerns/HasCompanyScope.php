<?php

declare(strict_types=1);

namespace App\Support\Concerns;

use App\Domain\User\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Multi-tenant guard rail. Any model carrying a `company_id` gains a
 * `forUser()` scope that restricts reads to the caller's own tenant unless the
 * caller holds a platform-wide role.
 */
trait HasCompanyScope
{
    public function scopeForUser(Builder $query, ?User $user): Builder
    {
        if ($user === null) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->isPlatformAdministrator()) {
            return $query;
        }

        if ($user->company_id !== null) {
            return $query->where($this->getTable().'.company_id', $user->company_id);
        }

        $owner = $this->ownerColumn();

        // No personal ownership column means the record cannot belong to a user
        // outside a company, so there is nothing for them to see.
        return $owner === null
            ? $query->whereRaw('1 = 0')
            : $query->where($owner, $user->getKey());
    }

    /**
     * The column tying a row to an individual owner, or null when the model has
     * none.
     *
     * Deliberately null by default rather than guessing `<table>.user_id`: the
     * guess produced a SQL error on every model without that column — Fleet
     * keys on `manager_id`, FraudAlert has no personal owner at all — which
     * surfaced as a 500 instead of an empty result. A tenancy guard rail should
     * fail closed.
     */
    protected function ownerColumn(): ?string
    {
        return null;
    }
}
