<?php

declare(strict_types=1);

namespace App\Domain\Vehicle\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string|null $logo_path
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, VehicleModel> $models
 * @property-read int|null $models_count
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VehicleMake newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VehicleMake newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VehicleMake query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VehicleMake whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VehicleMake whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VehicleMake whereLogoPath($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VehicleMake whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VehicleMake whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class VehicleMake extends Model
{
    protected $fillable = ['name', 'logo_path'];

    public function models(): HasMany
    {
        return $this->hasMany(VehicleModel::class, 'make_id');
    }
}
