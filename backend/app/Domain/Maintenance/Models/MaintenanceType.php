<?php

declare(strict_types=1);

namespace App\Domain\Maintenance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
