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

        return $user->isPlatformAdministrator() || $user->can('users.update');
    }

    public function delete(User $user, User $target): bool
    {
        if ($user->is($target) || $target->hasRole(config('fip.roles.super_admin'))) {
            return false;
        }

        return $user->isPlatformAdministrator() || $user->can('users.delete');
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
