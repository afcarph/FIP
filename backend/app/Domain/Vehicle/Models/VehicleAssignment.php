<?php

declare(strict_types=1);

namespace App\Domain\Vehicle\Models;

use App\Domain\Fleet\Models\Driver;
use App\Domain\User\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Historical record of which driver held which vehicle, and when.
 *
 * @property int $id
 * @property int $vehicle_id
 * @property int $driver_id
 * @property Carbon $assigned_at
 * @property Carbon|null $released_at
 * @property int|null $assigned_by
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $assigner
 * @property-read Driver|null $driver
 * @property-read Vehicle|null $vehicle
 *
 * @method static Builder<static>|VehicleAssignment active()
 * @method static Builder<static>|VehicleAssignment newModelQuery()
 * @method static Builder<static>|VehicleAssignment newQuery()
 * @method static Builder<static>|VehicleAssignment query()
 * @method static Builder<static>|VehicleAssignment whereAssignedAt($value)
 * @method static Builder<static>|VehicleAssignment whereAssignedBy($value)
 * @method static Builder<static>|VehicleAssignment whereCreatedAt($value)
 * @method static Builder<static>|VehicleAssignment whereDriverId($value)
 * @method static Builder<static>|VehicleAssignment whereId($value)
 * @method static Builder<static>|VehicleAssignment whereNotes($value)
 * @method static Builder<static>|VehicleAssignment whereReleasedAt($value)
 * @method static Builder<static>|VehicleAssignment whereUpdatedAt($value)
 * @method static Builder<static>|VehicleAssignment whereVehicleId($value)
 *
 * @mixin \Eloquent
 */
class VehicleAssignment extends Model
{
    protected $fillable = ['vehicle_id', 'driver_id', 'assigned_at', 'released_at', 'assigned_by', 'notes'];

    protected function casts(): array
    {
        return ['assigned_at' => 'datetime', 'released_at' => 'datetime'];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('released_at');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
