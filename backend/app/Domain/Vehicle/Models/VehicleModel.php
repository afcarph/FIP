<?php

declare(strict_types=1);

namespace App\Domain\Vehicle\Models;

use App\Domain\Pricing\Models\FuelType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VehicleModel extends Model
{
    protected $table = 'vehicle_models';

    protected $fillable = ['make_id', 'name', 'body_type', 'default_fuel_type_id', 'tank_capacity'];

    protected function casts(): array
    {
        return ['tank_capacity' => 'decimal:2'];
    }

    public function make(): BelongsTo
    {
        return $this->belongsTo(VehicleMake::class, 'make_id');
    }

    public function defaultFuelType(): BelongsTo
    {
        return $this->belongsTo(FuelType::class, 'default_fuel_type_id');
    }
}
