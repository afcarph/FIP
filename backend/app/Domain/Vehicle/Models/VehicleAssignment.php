<?php

declare(strict_types=1);

namespace App\Domain\Vehicle\Models;

use App\Domain\Fleet\Models\Driver;
use App\Domain\User\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Historical record of which driver held which vehicle, and when. */
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
