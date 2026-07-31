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

        return $query->where($this->ownerColumn(), $user->getKey());
    }

    protected function ownerColumn(): string
    {
        return $this->getTable().'.user_id';
    }
}
