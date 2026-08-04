<?php

declare(strict_types=1);

namespace App\Domain\Expense\Models;

use App\Domain\Fleet\Models\Driver;
use App\Domain\Fleet\Models\Fleet;
use App\Domain\Vehicle\Models\Vehicle;
use App\Support\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $vehicle_id
 * @property int|null $driver_id
 * @property int|null $fleet_id
 * @property string|null $reference_no
 * @property string|null $origin_label
 * @property float|null $origin_lat
 * @property float|null $origin_lng
 * @property string|null $destination_label
 * @property float|null $destination_lat
 * @property float|null $destination_lng
 * @property float|null $distance_km
 * @property int|null $duration_minutes
 * @property float|null $fuel_consumed_l
 * @property float|null $fuel_cost
 * @property float|null $toll_cost
 * @property Carbon|null $started_at
 * @property Carbon|null $ended_at
 * @property string $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Driver|null $driver
 * @property-read Fleet|null $fleet
 * @property-read RoutePlan|null $routePlan
 * @property-read Vehicle|null $vehicle
 *
 * @method static Builder<static>|Trip completed()
 * @method static Builder<static>|Trip newModelQuery()
 * @method static Builder<static>|Trip newQuery()
 * @method static Builder<static>|Trip onlyTrashed()
 * @method static Builder<static>|Trip query()
 * @method static Builder<static>|Trip whereCreatedAt($value)
 * @method static Builder<static>|Trip whereDeletedAt($value)
 * @method static Builder<static>|Trip whereDestinationLabel($value)
 * @method static Builder<static>|Trip whereDestinationLat($value)
 * @method static Builder<static>|Trip whereDestinationLng($value)
 * @method static Builder<static>|Trip whereDistanceKm($value)
 * @method static Builder<static>|Trip whereDriverId($value)
 * @method static Builder<static>|Trip whereDurationMinutes($value)
 * @method static Builder<static>|Trip whereEndedAt($value)
 * @method static Builder<static>|Trip whereFleetId($value)
 * @method static Builder<static>|Trip whereFuelConsumedL($value)
 * @method static Builder<static>|Trip whereFuelCost($value)
 * @method static Builder<static>|Trip whereId($value)
 * @method static Builder<static>|Trip whereOriginLabel($value)
 * @method static Builder<static>|Trip whereOriginLat($value)
 * @method static Builder<static>|Trip whereOriginLng($value)
 * @method static Builder<static>|Trip whereReferenceNo($value)
 * @method static Builder<static>|Trip whereStartedAt($value)
 * @method static Builder<static>|Trip whereStatus($value)
 * @method static Builder<static>|Trip whereTollCost($value)
 * @method static Builder<static>|Trip whereUpdatedAt($value)
 * @method static Builder<static>|Trip whereVehicleId($value)
 * @method static Builder<static>|Trip withTrashed(bool $withTrashed = true)
 * @method static Builder<static>|Trip withoutTrashed()
 *
 * @mixin \Eloquent
 */
class Trip extends Model
{
    use Auditable;
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'vehicle_id', 'driver_id', 'fleet_id', 'reference_no', 'origin_label',
        'origin_lat', 'origin_lng', 'destination_label', 'destination_lat',
        'destination_lng', 'distance_km', 'duration_minutes', 'fuel_consumed_l',
        'fuel_cost', 'toll_cost', 'started_at', 'ended_at', 'status',
    ];

    protected function casts(): array
    {
        return [
            'origin_lat' => 'float', 'origin_lng' => 'float',
            'destination_lat' => 'float', 'destination_lng' => 'float',
            'distance_km' => 'float', 'duration_minutes' => 'integer',
            'fuel_consumed_l' => 'float', 'fuel_cost' => 'float', 'toll_cost' => 'float',
            'started_at' => 'datetime', 'ended_at' => 'datetime',
        ];
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', 'completed');
    }

    public function totalCost(): float
    {
        return round((float) $this->fuel_cost + (float) $this->toll_cost, 2);
    }

    public function costPerKm(): ?float
    {
        return $this->distance_km ? round($this->totalCost() / $this->distance_km, 4) : null;
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function fleet(): BelongsTo
    {
        return $this->belongsTo(Fleet::class);
    }

    public function routePlan(): HasOne
    {
        return $this->hasOne(RoutePlan::class);
    }
}
