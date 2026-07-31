<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Expense\Models\FuelPurchase;
use App\Domain\User\Models\User;

class FuelPurchasePolicy
{
    public function view(User $user, FuelPurchase $purchase): bool
    {
        if ($user->isPlatformAdministrator() || $purchase->user_id === $user->getKey()) {
            return true;
        }

        return $purchase->vehicle?->company_id !== null
            && $purchase->vehicle->company_id === $user->company_id;
    }

    public function update(User $user, FuelPurchase $purchase): bool
    {
        return $purchase->isEditableBy($user) && $this->view($user, $purchase);
    }

    public function delete(User $user, FuelPurchase $purchase): bool
    {
        if ($user->isPlatformAdministrator()) {
            return true;
        }

        // Deleting a company fill-up destroys audit value; managers only.
        if ($purchase->vehicle?->company_id !== null) {
            return $purchase->vehicle->company_id === $user->company_id
                && $user->hasAnyRole([config('fip.roles.fleet_manager'), config('fip.roles.company_manager')]);
        }

        return $purchase->user_id === $user->getKey();
    }
}
