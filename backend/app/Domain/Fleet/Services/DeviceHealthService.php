<?php

declare(strict_types=1);

namespace App\Domain\Fleet\Services;

use App\Domain\User\Models\User;
use App\Domain\User\Models\UserDevice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The operational health of the devices a fleet depends on.
 *
 * A vehicle is only visible on the map for as long as the handset in it is
 * awake, charged and in signal. That makes the handset fleet equipment, and
 * "which of my devices has gone quiet" a fleet question rather than a personal
 * one — which is the whole reason this reads across a company at all.
 *
 * It reads narrowly on purpose. See scopeForFleetHealth on UserDevice: only
 * devices attached to one of the company's vehicles are in scope, never the
 * personal handsets of everyone who happens to work there.
 */
class DeviceHealthService
{
    public const FILTERS = ['online', 'offline', 'low_battery', 'charging'];

    /**
     * Record what a device says about itself.
     *
     * forceFill rather than fill: the battery columns are deliberately absent
     * from $fillable so that no ordinary device update can write them. Only
     * this path, reached only by the device reporting about itself, may.
     */
    public function record(UserDevice $device, array $data): void
    {
        $device->forceFill([
            'battery_percentage' => $data['battery_percentage'],
            'battery_state' => $data['battery_state'],
            'battery_updated_at' => $this->reportedAt($data['recorded_at'] ?? null),

            // A health report is contact, so it counts as being seen. Without
            // this a parked vehicle would read as offline while its device is
            // awake and reporting — the movement filter means a stationary
            // device sends no positions, and last_seen_at would never move.
            'last_seen_at' => now(),
        ])->save();
    }

    /**
     * When the device says it read the battery, bounded by when we heard it.
     *
     * A clock ahead of the server would otherwise park a reading in the future,
     * where every staleness check treats it as permanently fresh. Clamping
     * forward rather than rejecting: a wrong clock is a reason to distrust the
     * timestamp, not to throw away the only battery reading we have.
     */
    private function reportedAt(?string $claimed): Carbon
    {
        if ($claimed === null) {
            return now();
        }

        $reported = Carbon::parse($claimed);

        return $reported->isFuture() ? now() : $reported;
    }

    /**
     * The health listing for one user, already scoped to what they may see.
     *
     * @param string|null $filter one of FILTERS, or null for everything
     * @return Builder<UserDevice>
     */
    public function fleetQuery(User $user, ?string $filter = null): Builder
    {
        $query = UserDevice::query()
            ->forFleetHealth($user)
            ->with(['user:id,first_name,last_name,email', 'vehicle:id,plate_number,nickname,company_id']);

        return $this->applyFilter($query, $filter);
    }

    /**
     * @param Builder<UserDevice> $query
     * @return Builder<UserDevice>
     */
    private function applyFilter(Builder $query, ?string $filter): Builder
    {
        $offlineBefore = now()->subMinutes((int) config('fip.device_health.offline_after_minutes'));
        $batteryFresh = now()->subMinutes((int) config('fip.device_health.battery_stale_after_minutes'));
        $lowPct = (int) config('fip.device_health.low_battery_pct');

        return match ($filter) {
            'online' => $query->where('last_seen_at', '>', $offlineBefore),

            // A device that has never reported at all is offline, not missing.
            // whereNull is not redundant with the comparison: NULL > x is NULL
            // in SQL, so a never-seen device would fall out of both filters.
            'offline' => $query->where(fn (Builder $q) => $q
                ->whereNull('last_seen_at')
                ->orWhere('last_seen_at', '<=', $offlineBefore)),

            // Charging is not low, however little charge is in it — a phone on
            // 5% on a dashboard charger needs nobody's attention. And a stale
            // reading is not evidence of anything.
            'low_battery' => $query
                ->whereNotNull('battery_percentage')
                ->where('battery_percentage', '<=', $lowPct)
                ->whereNotIn('battery_state', ['charging', 'full'])
                ->where('battery_updated_at', '>', $batteryFresh),

            'charging' => $query
                ->whereIn('battery_state', ['charging', 'full'])
                ->where('battery_updated_at', '>', $batteryFresh),

            default => $query,
        };
    }

    /**
     * Counts for the filter chips, so the view can say how many are offline
     * before anybody clicks offline.
     *
     * @return array<string, int>
     */
    public function summary(User $user): array
    {
        $counts = ['total' => $this->fleetQuery($user)->count()];

        foreach (self::FILTERS as $filter) {
            $counts[$filter] = $this->fleetQuery($user, $filter)->count();
        }

        return $counts;
    }
}
