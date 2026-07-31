<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Pricing\Models\PriceReport;
use App\Domain\User\Models\User;

class PriceReportPolicy
{
    public function viewAny(?User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        // Suspended or banned accounts cannot pollute the price data.
        return $user->isActive();
    }

    public function moderate(User $user): bool
    {
        return $user->isPlatformAdministrator() || $user->can('prices.moderate');
    }

    public function vote(User $user, PriceReport $report): bool
    {
        return $user->isActive() && $report->user_id !== $user->getKey();
    }
}
