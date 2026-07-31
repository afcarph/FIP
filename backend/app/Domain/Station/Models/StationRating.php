<?php

declare(strict_types=1);

namespace App\Domain\Station\Models;

use App\Domain\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class StationRating extends Model
{
    use SoftDeletes;

    protected $fillable = ['station_id', 'user_id', 'rating', 'comment'];

    protected function casts(): array
    {
        return ['rating' => 'integer'];
    }

    protected static function booted(): void
    {
        // Keep the denormalised aggregate on gas_stations in step.
        static::saved(fn (self $r) => $r->station?->recalculateRating());
        static::deleted(fn (self $r) => $r->station?->recalculateRating());
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(GasStation::class, 'station_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
