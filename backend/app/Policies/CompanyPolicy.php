<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\User\Models\Company;
use App\Domain\User\Models\User;

/**
 * Who may act on a tenant.
 *
 * A company is the tenant boundary itself, so it cannot be governed the way
 * records inside one are. Creating a company, and deciding what it is
 * entitled to, are platform decisions: a tenant that could raise its own
 * subscription tier would be setting its own bill.
 *
 * The one thing a tenant may do is look at itself. A company manager reading
 * their own company — its name, its tier, what it has used against its limits
 * — is how they find out they are out of seats, which is the difference
 * between an actionable 402 and a mystery.
 */
class CompanyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isPlatformAdministrator() || $user->can('companies.view');
    }

    public function view(User $user, Company $company): bool
    {
        if ($user->isPlatformAdministrator() || $user->can('companies.view')) {
            return true;
        }

        // Your own tenant, and only your own.
        return $user->company_id !== null && $user->company_id === $company->getKey();
    }

    public function create(User $user): bool
    {
        return $user->isPlatformAdministrator() || $user->can('companies.create');
    }

    /**
     * Editing a tenant stays with the platform.
     *
     * Deliberately not extended to a company manager editing their own
     * company: the same endpoint carries subscription_tier, and separating
     * "rename my company" from "grant myself a bigger plan" needs a narrower
     * endpoint than this one. Until that exists, the safe answer is no.
     */
    public function update(User $user, Company $company): bool
    {
        return $user->isPlatformAdministrator() || $user->can('companies.update');
    }
}
