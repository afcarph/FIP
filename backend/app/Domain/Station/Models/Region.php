<?php

declare(strict_types=1);

namespace App\Domain\Station\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Region extends Model
{
    protected $fillable = ['code', 'name', 'island_group'];

    public function provinces(): HasMany
    {
        return $this->hasMany(Province::class);
    }
}
