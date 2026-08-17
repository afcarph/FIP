<?php

declare(strict_types=1);

namespace App\Domain\Fleet\Models;

use App\Domain\Fleet\Services\LocationRetentionService;
use App\Domain\User\Models\UserDevice;
use App\Domain\Vehicle\Models\Vehicle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Where a registered device was, at a moment it reported.
 *
 * Append-only. `vehicle_id` is stamped from the device's association when the
 * row is written rather than resolved through the device on read, so a device
 * moved to another vehicle next week does not silently rewrite this week's
 * journeys.
 *
 * @property int $id
 * @property int $device_id
 * @property int|null $vehicle_id
 * @property float $latitude
 * @property float $longitude
 * @property float|null $accuracy_m
 * @property float|null $altitude_m
 * @property float|null $speed_kph
 * @property float|null $heading_deg
 * @property Carbon $recorded_at
 * @property Carbon $received_at
 * @property-read UserDevice|null $device
 * @property-read Vehicle|null $vehicle
 *
 * @method static Builder<static>|DeviceLocation newModelQuery()
 * @method static Builder<static>|DeviceLocation newQuery()
 * @method static Builder<static>|DeviceLocation query()
 *
 * @mixin \Eloquent
 */
class DeviceLocation extends Model
{
    use Prunable;

    /** Positions carry their own two clocks; Eloquent's pair would add nothing. */
    public $timestamps = false;

    protected $fillable = [
        'device_id', 'vehicle_id', 'latitude', 'longitude',
        'accuracy_m', 'altitude_m', 'speed_kph', 'heading_deg',
        'recorded_at', 'received_at',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'accuracy_m' => 'float',
            'altitude_m' => 'float',
            'speed_kph' => 'float',
            'heading_deg' => 'float',
            'recorded_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }

    /**
     * Rows old enough to delete.
     *
     * This is the first Prunable model in the platform: `model:prune` has been
     * on the daily schedule since the beginning with nothing implementing the
     * interface, so every documented retention period was until now an
     * intention rather than a mechanism. Location is the category where that
     * gap matters most, so it is the one that closes it.
     *
     * The window itself is configuration and provisional — see
     * config/fip.php and docs/07-security.md. A retention period of zero or
     * less disables pruning rather than deleting everything, because an
     * unset value should never be read as "delete it all".
     */
    public function prunable(): Builder
    {
        // The administrator's setting first, the environment fallback second.
        // Resolved rather than injected because the pruner instantiates models
        // itself, and a period read at prune time is the period in force now.
        $days = app(LocationRetentionService::class)->days();

        // Nothing configured anywhere means delete nothing. An absent setting
        // is not an instruction to erase a driver's history, and a pruner that
        // reads a missing value as "older than zero days" would erase all of it.
        if ($days <= 0) {
            return static::query()->whereRaw('1 = 0');
        }

        return static::query()->where('recorded_at', '<', now()->subDays($days));
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(UserDevice::class, 'device_id');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }
}
