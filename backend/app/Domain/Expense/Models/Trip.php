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
