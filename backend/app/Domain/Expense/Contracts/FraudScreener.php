<?php

declare(strict_types=1);

namespace App\Domain\Expense\Contracts;

use App\Domain\Ai\Models\FraudAlert;
use App\Domain\Expense\Models\FuelPurchase;

/**
 * Port through which the Expense context screens a fill-up for anomalies.
 *
 * Owned by Expense — the consumer — and implemented in the Ai context, so that
 * recording an expense does not depend on a concrete AI class. It also keeps
 * the expense arithmetic testable in isolation from the scoring rules.
 */
interface FraudScreener
{
    /** Score one purchase, returning an alert when it crosses the threshold. */
    public function screen(FuelPurchase $purchase): ?FraudAlert;
}
