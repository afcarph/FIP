<?php

declare(strict_types=1);

namespace App\Domain\Station\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Amenity extends Model
{
    protected $fillable = ['code', 'name', 'icon'];

    public function stations(): BelongsToMany
    {
        return $this->belongsToMany(GasStation::class, 'station_amenity', 'amenity_id', 'station_id');
    }
}
