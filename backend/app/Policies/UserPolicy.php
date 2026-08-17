<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\User\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isPlatformAdministrator() || $user->can('users.view');
    }

    public function view(User $user, User $target): bool
    {
        return $user->is($target)
            || $user->isPlatformAdministrator()
            || ($target->company_id !== null && $target->company_id === $user->company_id && $user->can('users.view'));
    }

    public function create(User $user): bool
    {
        return $user->isPlatformAdministrator() || $user->can('users.create');
    }

    public function update(User $user, User $target): bool
    {
        if ($user->is($target)) {
            return true;
        }

        // Nobody but a super administrator may modify a super administrator.
        if ($target->hasRole(config('fip.roles.super_admin'))) {
            return $user->hasRole(config('fip.roles.super_admin'));
        }

        if ($user->isPlatformAdministrator()) {
            return true;
        }

        return $user->can('users.update') && $this->sharesTenant($user, $target);
    }

    public function delete(User $user, User $target): bool
    {
        if ($user->is($target) || $target->hasRole(config('fip.roles.super_admin'))) {
            return false;
        }

        if ($user->isPlatformAdministrator()) {
            return true;
        }

        return $user->can('users.delete') && $this->sharesTenant($user, $target);
    }

    /**
     * Whether both people belong to the same tenant.
     *
     * The users.* permissions say what a role may do, never to whom. Without
     * this, company_manager — which holds users.view, users.create and
     * users.update — reached every user on the platform, so a tenant admin
     * could rename, suspend or reassign somebody in a company they have no
     * relationship with.
     *
     * A null company fails closed on both sides: someone outside any tenant
     * shares a tenant with nobody, and two companyless users are not
     * colleagues just because both fields are null.
     */
    private function sharesTenant(User $user, User $target): bool
    {
        return $user->company_id !== null && $user->company_id === $target->company_id;
    }

    // ------------------------------------------------- platform abilities ---

    public function viewExecutiveDashboard(User $user): bool
    {
        return $user->isPlatformAdministrator() || $user->can('analytics.platform');
    }

    public function viewAuditLogs(User $user): bool
    {
        return $user->isPlatformAdministrator() || $user->can('audit.view');
    }

    public function manageRoles(User $user): bool
    {
        return $user->isPlatformAdministrator() || $user->can('roles.manage');
    }

    public function manageAiModels(User $user): bool
    {
        return $user->isPlatformAdministrator() || $user->can('ai.manage');
    }
}
