<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Ai\Services\FraudDetectionService;
use App\Domain\Expense\Models\FuelPurchase;
use App\Domain\Notification\Services\NotificationService;
use App\Domain\User\Models\Company;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Nightly tier-2 fraud pass over each company's recent transactions.
 *
 * Runs per company so one large fleet cannot starve the others, and so the
 * unsupervised model sees a coherent population to compare against.
 */
class ScreenFleetForFraud implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 300;

    public function __construct(
        private readonly int $companyId,
        private readonly int $lookbackDays = 30,
    ) {}

    public function handle(FraudDetectionService $fraud, NotificationService $notifications): void
    {
        $company = Company::with('users')->find($this->companyId);

        if ($company === null) {
            return;
        }

        $purchases = FuelPurchase::query()
            ->whereIn('vehicle_id', $company->vehicles()->select('id'))
            ->where('purchased_at', '>=', now()->subDays($this->lookbackDays))
            ->with('vehicle')
            ->get();

        $alerts = $fraud->screenBatch($purchases);

        if ($alerts === []) {
            return;
        }

        $managers = $company->users()
            ->whereHas('roles', fn ($q) => $q->whereIn('name', [
                config('fip.roles.fleet_manager'),
                config('fip.roles.company_manager'),
            ]))
            ->get();

        foreach ($managers as $manager) {
            $notifications->send($manager, 'fraud_digest', 'ai', [
                'title' => 'Possible fuel anomalies detected',
                'body' => sprintf(
                    '%d new alert%s from the last %d days need review.',
                    count($alerts),
                    count($alerts) === 1 ? '' : 's',
                    $this->lookbackDays,
                ),
                'priority' => 'high',
                'data' => ['alert_count' => count($alerts)],
                'action_url' => '/fleet/fraud-alerts',
            ]);
        }
    }
}
