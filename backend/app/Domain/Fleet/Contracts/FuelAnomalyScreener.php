<?php

declare(strict_types=1);

namespace App\Domain\Fleet\Contracts;

use App\Domain\Ai\Models\FraudAlert;
use App\Domain\Fleet\Models\VehicleFuelReading;

/**
 * Port through which the Fleet context screens a fuel reading for anomalies.
 *
 * Owned by Fleet — the consumer — and implemented alongside it, mirroring how
 * Expense owns FraudScreener. FuelLevelService therefore depends on an
 * interface rather than a detector, which keeps the ordering and delta rules
 * testable without the scoring logic, and lets the screener be swapped for a
 * null implementation wherever detection is not wanted.
 */
interface FuelAnomalyScreener
{
    /** Score one reading, returning an alert when it crosses the threshold. */
    public function screen(VehicleFuelReading $reading): ?FraudAlert;
}
