<?php

declare(strict_types=1);

use App\Domain\User\Models\Company;
use App\Jobs\ScreenFleetForFraud;
use App\Jobs\SendMaintenanceReminders;
use Illuminate\Support\Facades\Schedule;

/*
|---------------------------------------------------------------------------
| Scheduled tasks
|---------------------------------------------------------------------------
| Everything runs in Asia/Manila. `withoutOverlapping` guards the long jobs
| so a slow run never stacks on top of itself; `onOneServer` keeps duplicates
| off a multi-node deployment.
*/

// Market data feeds the Monday forecast, so it must land first.
Schedule::command('fip:refresh-indicators')
    ->dailyAt('01:00')
    ->timezone('Asia/Manila')
    ->onOneServer();

// Weekly forecast, generated Monday 02:00 for Tuesday's DOE adjustment.
Schedule::command('fip:forecast --notify')
    ->weeklyOn(1, '02:00')
    ->timezone('Asia/Manila')
    ->withoutOverlapping()
    ->onOneServer();

// The DOE publishes Monday evening; import and apply Tuesday morning.
Schedule::command('fip:import-advisories')
    ->weeklyOn(2, '05:30')
    ->timezone('Asia/Manila')
    ->withoutOverlapping()
    ->onOneServer();

// Score last week's forecast against what actually happened.
Schedule::command('fip:forecast --week='.now()->startOfWeek()->toDateString())
    ->weeklyOn(3, '08:00')
    ->timezone('Asia/Manila')
    ->onOneServer();

// Maintenance: recalculate first, then remind.
Schedule::command('fip:refresh-maintenance')
    ->dailyAt('03:00')
    ->timezone('Asia/Manila')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::job(new SendMaintenanceReminders(14))
    ->dailyAt('08:00')
    ->timezone('Asia/Manila')
    ->onOneServer();

// Nightly tier-2 fraud sweep, one job per company.
Schedule::call(function (): void {
    Company::where('is_active', true)->pluck('id')->each(
        fn (int $companyId) => ScreenFleetForFraud::dispatch($companyId)->onQueue('ai'),
    );
})->name('fip:fraud-sweep')->dailyAt('02:30')->timezone('Asia/Manila')->onOneServer();

// Housekeeping.
Schedule::command('queue:prune-failed --hours=336')->weekly()->onOneServer();
// --path is required, not decorative: model:prune defaults to app/Models,
// which does not exist in this project — every model lives under
// app/Domain/<Context>/Models. Without it the command scans an empty path and
// reports success having pruned nothing, which is how a retention policy
// becomes a promise instead of a mechanism.
Schedule::command('model:prune', ['--path' => 'app/Domain'])->daily()->onOneServer();
