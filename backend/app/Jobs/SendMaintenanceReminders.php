<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Maintenance\Models\MaintenanceSchedule;
use App\Domain\Notification\Services\NotificationService;
use App\Domain\Vehicle\Models\Vehicle;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Daily sweep for service items and legal documents coming due.
 *
 * Recipients are resolved per vehicle: private owner, or the fleet manager
 * plus the assigned driver for company assets.
 */
class SendMaintenanceReminders implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(private readonly int $withinDays = 14) {}

    public function handle(NotificationService $notifications): int
    {
        $sent = 0;

        MaintenanceSchedule::query()
            ->dueWithin($this->withinDays)
            ->with(['type', 'vehicle.owner', 'vehicle.fleet.manager', 'vehicle.currentAssignment.driver.user'])
            ->chunkById(200, function ($schedules) use ($notifications, &$sent): void {
                foreach ($schedules as $schedule) {
                    if ($schedule->vehicle === null) {
                        continue;
                    }

                    $template = match ($schedule->type?->code) {
                        'registration' => 'registration_expiry',
                        'insurance' => 'insurance_expiry',
                        default => 'maintenance_due',
                    };

                    foreach ($this->recipientsFor($schedule->vehicle) as $recipient) {
                        $notifications->send($recipient, 'maintenance_reminder', 'maintenance', [
                            'template' => $template,
                            'variables' => [
                                'vehicle' => $schedule->vehicle->display_name,
                                'service' => $schedule->type?->name,
                                'date' => $schedule->due_at?->toFormattedDateString(),
                            ],
                            'priority' => $schedule->status === MaintenanceSchedule::STATUS_OVERDUE ? 'high' : 'normal',
                            'data' => [
                                'vehicle_id' => $schedule->vehicle_id,
                                'schedule_id' => $schedule->getKey(),
                            ],
                            'action_url' => "/vehicles/{$schedule->vehicle_id}/maintenance",
                        ]);

                        $sent++;
                    }
                }
            });

        return $sent;
    }

    /** @return array<int, \App\Domain\User\Models\User> */
    private function recipientsFor(Vehicle $vehicle): array
    {
        $recipients = array_filter([
            $vehicle->owner,
            $vehicle->fleet?->manager,
            $vehicle->currentAssignment?->driver?->user,
        ]);

        // De-duplicate: an owner who is also the fleet manager gets one message.
        return collect($recipients)->unique->getKey()->values()->all();
    }
}
