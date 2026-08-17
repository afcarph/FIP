<?php

declare(strict_types=1);

namespace App\Domain\Fleet\Services;

use App\Domain\Expense\Models\FuelPurchase;
use App\Domain\Fleet\Models\Driver;
use App\Domain\User\Models\User;
use App\Domain\User\Models\UserDevice;
use App\Domain\Vehicle\Models\Vehicle;
use App\Domain\Vehicle\Models\VehicleAssignment;

/**
 * How far a new company has got.
 *
 * A tenant that has just registered lands on a fleet dashboard where every
 * figure is zero. That is accurate and useless: it says what is true without
 * saying what to do, and the product's whole value is behind four steps nobody
 * has been told about.
 *
 * Every step here is *derived from real records* rather than ticked off in a
 * stored checklist. A checklist is a second copy of the truth: it drifts the
 * moment somebody deletes their only vehicle, and then congratulates a company
 * for something it no longer has. Asking the tables costs four counts and can
 * never be wrong.
 *
 * The order is the order the product actually requires — a driver cannot be
 * assigned before a vehicle exists, and a phone cannot report a position
 * before it is attached to one — so a company working down the list never
 * meets a step it cannot yet complete.
 */
final class OnboardingService
{
    /**
     * @return array<string, mixed>
     */
    public function forCompany(int $companyId): array
    {
        $vehicles = Vehicle::query()->where('company_id', $companyId)->count();
        $drivers = Driver::query()->where('company_id', $companyId)->count();

        $assigned = VehicleAssignment::query()
            ->active()
            ->whereIn('vehicle_id', Vehicle::query()->where('company_id', $companyId)->select('id'))
            ->exists();

        // Only a handset counts. A browser registration is a session identity
        // and can never report a position, so treating one as "you have a
        // device" would tick a step the fleet has not actually taken.
        $device = UserDevice::query()
            ->countsTowardPlan()
            ->whereNull('revoked_at')
            ->whereIn('user_id', User::query()->where('company_id', $companyId)->select('id'))
            ->exists();

        $fuel = FuelPurchase::query()
            ->whereIn('vehicle_id', Vehicle::query()->where('company_id', $companyId)->select('id'))
            ->exists();

        $steps = [
            [
                'key' => 'vehicle',
                'title' => 'Add your first vehicle',
                'description' => 'Everything else hangs off a vehicle — fuel, maintenance, trips and position.',
                'href' => '/vehicles/new',
                'action' => 'Add a vehicle',
                'done' => $vehicles > 0,
            ],
            [
                'key' => 'driver',
                'title' => 'Add a driver',
                'description' => 'The people who will drive, log fill-ups and run trips.',
                'href' => '/fleet/drivers',
                'action' => 'Add a driver',
                'done' => $drivers > 0,
            ],
            [
                'key' => 'assignment',
                'title' => 'Put a driver in a vehicle',
                'description' => 'An assignment is what links the two, and what a driver’s phone reports against.',
                'href' => '/fleet/assignments',
                'action' => 'Assign a driver',
                'done' => $assigned,
            ],
            [
                'key' => 'device',
                'title' => 'Get the app on a driver’s phone',
                'description' => 'A registered handset is what reports position and battery. Nothing is tracked until one is.',
                'href' => '/fleet/devices',
                'action' => 'See device health',
                'done' => $device,
            ],
            [
                'key' => 'fuel',
                'title' => 'Log a fill-up',
                'description' => 'Spend, efficiency and anomaly detection all start from the first one.',
                'href' => '/expenses/new',
                'action' => 'Log a fill-up',
                'done' => $fuel,
            ],
        ];

        $completed = count(array_filter($steps, static fn (array $step): bool => $step['done']));

        return [
            'steps' => $steps,
            'completed' => $completed,
            'total' => count($steps),
            // The one thing the client needs in order to decide whether to
            // show anything at all, computed here so it cannot be worked out
            // two different ways in two different screens.
            'is_complete' => $completed === count($steps),
        ];
    }
}
