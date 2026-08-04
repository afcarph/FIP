<?php

declare(strict_types=1);

namespace App\Domain\Vehicle\Models;

use App\Domain\Pricing\Models\FuelType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $make_id
 * @property string $name
 * @property string|null $body_type
 * @property int|null $default_fuel_type_id
 * @property numeric|null $tank_capacity
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read FuelType|null $defaultFuelType
 * @property-read VehicleMake $make
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VehicleModel newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VehicleModel newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VehicleModel query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VehicleModel whereBodyType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VehicleModel whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VehicleModel whereDefaultFuelTypeId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VehicleModel whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VehicleModel whereMakeId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VehicleModel whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VehicleModel whereTankCapacity($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VehicleModel whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
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
