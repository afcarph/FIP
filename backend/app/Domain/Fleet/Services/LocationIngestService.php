<?php

declare(strict_types=1);

namespace App\Domain\Fleet\Services;

use App\Domain\Fleet\Models\DeviceLocation;
use App\Domain\User\Models\UserDevice;
use App\Support\Exceptions\DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Accepts position reports from a registered device.
 *
 * Everything about ownership is decided here, on the server, from the resolved
 * device. The request body carries measurements and nothing else: a client that
 * sends `vehicle_id`, `device_id` or `user_id` is ignored, because a field that
 * is trusted from the body is a field an attacker sets.
 *
 * Batching is the default rather than an optimisation. A vehicle loses signal
 * constantly, so the client queues locally and flushes what it has; accepting
 * one point per request would turn a tunnel into a hundred failed calls. The
 * unique key on (device, recorded_at) makes a re-flush after a dropped
 * connection a no-op rather than a duplicated journey.
 */
final readonly class LocationIngestService
{
    /**
     * @param array<int, array<string, mixed>> $points
     * @return array{accepted: int, duplicates: int, rejected: array<int, array{index: int, reason: string}>}
     */
    public function ingest(UserDevice $device, array $points): array
    {
        if (! $device->canReportLocation()) {
            throw new DomainException(
                $device->isRevoked()
                    ? 'This device has been revoked and can no longer report location.'
                    : 'This device is not assigned to a vehicle.',
                $device->isRevoked() ? 'device_revoked' : 'device_unassigned',
                403,
            );
        }

        $max = (int) config('fip.location.max_batch_size');

        if (count($points) > $max) {
            throw new DomainException(
                sprintf('A batch may carry at most %d positions; received %d.', $max, count($points)),
                'batch_too_large',
                422,
            );
        }

        $receivedAt = now();
        $rows = [];
        $rejected = [];

        foreach ($points as $index => $point) {
            $reason = $this->reject($point, $receivedAt);

            if ($reason !== null) {
                // One bad point does not fail the flush. A device with a single
                // wild fix would otherwise be unable to deliver the good ones
                // behind it, and would retry the same poisoned batch forever.
                $rejected[] = ['index' => $index, 'reason' => $reason];

                continue;
            }

            $recordedAt = $this->normalise($point['recorded_at']);

            $rows[$recordedAt->toDateTimeString()] = [
                'device_id' => $device->getKey(),
                // Stamped from the device's association, never from the body.
                'vehicle_id' => $device->vehicle_id,
                'latitude' => round((float) $point['latitude'], 7),
                'longitude' => round((float) $point['longitude'], 7),
                'accuracy_m' => $this->optional($point, 'accuracy_m'),
                'altitude_m' => $this->optional($point, 'altitude_m'),
                'speed_kph' => $this->optional($point, 'speed_kph'),
                'heading_deg' => $this->optional($point, 'heading_deg'),
                'recorded_at' => $recordedAt,
                'received_at' => $receivedAt,
            ];
        }

        if ($rows === []) {
            return ['accepted' => 0, 'duplicates' => 0, 'rejected' => $rejected];
        }

        // Keyed by timestamp above, so a batch that repeats a moment within
        // itself collapses before it reaches the unique index.
        $rows = array_values($rows);

        $accepted = DB::transaction(function () use ($device, $rows): int {
            $before = DeviceLocation::where('device_id', $device->getKey())->count();

            // insertOrIgnore, not insert: a retried flush overlapping what
            // already landed is the normal case, not an error worth a 409.
            DeviceLocation::insertOrIgnore($rows);

            $after = DeviceLocation::where('device_id', $device->getKey())->count();

            $this->refreshDeviceState($device, $rows);

            return $after - $before;
        });

        return [
            'accepted' => $accepted,
            'duplicates' => count($rows) - $accepted,
            'rejected' => $rejected,
        ];
    }

    /**
     * Update the device's cached position — but only from the newest point in
     * the batch, and only if it beats what is already stored. A queue flushed
     * after an hour offline arrives full of old positions, and the dashboard
     * must not rewind to where the vehicle was an hour ago.
     *
     * @param array<int, array<string, mixed>> $rows
     */
    private function refreshDeviceState(UserDevice $device, array $rows): void
    {
        $newest = collect($rows)->sortByDesc(fn (array $row) => $row['recorded_at'])->first();

        if ($newest === null) {
            return;
        }

        $recordedAt = $newest['recorded_at'];

        if ($device->last_location_at !== null && $recordedAt->lt($device->last_location_at)) {
            $device->forceFill(['last_seen_at' => now()])->save();

            return;
        }

        $device->forceFill([
            'last_latitude' => $newest['latitude'],
            'last_longitude' => $newest['longitude'],
            'last_location_at' => $recordedAt,
            'last_seen_at' => now(),
        ])->save();
    }

    /**
     * Why a point cannot be stored, or null when it can.
     *
     * Returns a reason rather than throwing so the caller can report which
     * points failed and why: a client that is told "422" learns nothing about
     * what to stop sending.
     */
    private function reject(array $point, Carbon $receivedAt): ?string
    {
        $latitude = $point['latitude'] ?? null;
        $longitude = $point['longitude'] ?? null;

        if (! is_numeric($latitude) || ! is_numeric($longitude)) {
            return 'latitude and longitude are required and must be numeric';
        }

        if ((float) $latitude < -90 || (float) $latitude > 90) {
            return 'latitude is outside -90..90';
        }

        if ((float) $longitude < -180 || (float) $longitude > 180) {
            return 'longitude is outside -180..180';
        }

        // Null Island. A device reporting exactly (0, 0) has almost certainly
        // failed to get a fix and defaulted, rather than sailing the Gulf of
        // Guinea, and letting it through puts a fake point on the map.
        if ((float) $latitude === 0.0 && (float) $longitude === 0.0) {
            return 'coordinates are null island (0, 0), which indicates a failed fix';
        }

        if (! isset($point['recorded_at'])) {
            return 'recorded_at is required';
        }

        try {
            $recordedAt = $this->normalise($point['recorded_at']);
        } catch (\Throwable) {
            return 'recorded_at is not a parseable timestamp';
        }

        $skew = (int) config('fip.location.max_clock_skew_minutes');

        if ($recordedAt->gt($receivedAt->copy()->addMinutes($skew))) {
            return sprintf('recorded_at is more than %d minutes in the future', $skew);
        }

        // Older than the retention window is not worth storing: the pruner
        // would delete it on its next pass anyway.
        $retention = (int) config('fip.location.retention_days');

        if ($retention > 0 && $recordedAt->lt($receivedAt->copy()->subDays($retention))) {
            return sprintf('recorded_at is older than the %d day retention window', $retention);
        }

        $accuracy = $point['accuracy_m'] ?? null;
        $maxAccuracy = (float) config('fip.location.max_accuracy_metres');

        if ($accuracy !== null && (! is_numeric($accuracy) || (float) $accuracy < 0)) {
            return 'accuracy_m must be a positive number';
        }

        // A fix accurate to within several kilometres is worse than none: it
        // renders as a confident pin in the wrong suburb.
        if ($accuracy !== null && (float) $accuracy > $maxAccuracy) {
            return sprintf('accuracy of %.0f m exceeds the %.0f m limit', (float) $accuracy, $maxAccuracy);
        }

        return null;
    }

    /**
     * Parse a client timestamp into the application's timezone.
     *
     * A device reports UTC; the server and MySQL both run Asia/Manila. Eloquent
     * writes a Carbon using whatever zone it carries, so parsing without
     * converting stored `recorded_at` as a UTC wall-clock next to a
     * `received_at` written in local time — two columns in one row on different
     * clocks, eight hours apart.
     *
     * It was not merely untidy. Reading the column back cast it as local time,
     * so a replayed older position compared as *newer* than the stored newest
     * and overwrote the cached last-known location; history queries built from
     * now() would have missed by the same eight hours.
     */
    private function normalise(mixed $value): Carbon
    {
        return Carbon::parse($value)->setTimezone(config('app.timezone'));
    }

    private function optional(array $point, string $key): ?float
    {
        $value = $point[$key] ?? null;

        return is_numeric($value) ? round((float) $value, 2) : null;
    }
}
