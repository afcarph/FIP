<?php

declare(strict_types=1);

namespace App\Domain\Maintenance\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string $category
 * @property int|null $default_interval_km
 * @property int|null $default_interval_days
 * @property string|null $icon
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, MaintenanceRecord> $records
 * @property-read int|null $records_count
 * @property-read Collection<int, MaintenanceSchedule> $schedules
 * @property-read int|null $schedules_count
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MaintenanceType newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MaintenanceType newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MaintenanceType query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MaintenanceType whereCategory($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MaintenanceType whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MaintenanceType whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MaintenanceType whereDefaultIntervalDays($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MaintenanceType whereDefaultIntervalKm($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MaintenanceType whereIcon($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MaintenanceType whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MaintenanceType whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MaintenanceType whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class MaintenanceType extends Model
{
    protected $fillable = [
        'code', 'name', 'category', 'default_interval_km', 'default_interval_days', 'icon',
    ];

    protected function casts(): array
    {
        return ['default_interval_km' => 'integer', 'default_interval_days' => 'integer'];
    }

    public function records(): HasMany
    {
        return $this->hasMany(MaintenanceRecord::class);
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(MaintenanceSchedule::class);
    }
}
