<?php

declare(strict_types=1);

namespace App\Domain\Vehicle\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $vehicle_id
 * @property string $type
 * @property string|null $number
 * @property Carbon|null $issued_on
 * @property Carbon|null $expires_on
 * @property string|null $file_path
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Vehicle|null $vehicle
 *
 * @method static Builder<static>|VehicleDocument expiringWithin(int $days)
 * @method static Builder<static>|VehicleDocument newModelQuery()
 * @method static Builder<static>|VehicleDocument newQuery()
 * @method static Builder<static>|VehicleDocument onlyTrashed()
 * @method static Builder<static>|VehicleDocument query()
 * @method static Builder<static>|VehicleDocument whereCreatedAt($value)
 * @method static Builder<static>|VehicleDocument whereDeletedAt($value)
 * @method static Builder<static>|VehicleDocument whereExpiresOn($value)
 * @method static Builder<static>|VehicleDocument whereFilePath($value)
 * @method static Builder<static>|VehicleDocument whereId($value)
 * @method static Builder<static>|VehicleDocument whereIssuedOn($value)
 * @method static Builder<static>|VehicleDocument whereNotes($value)
 * @method static Builder<static>|VehicleDocument whereNumber($value)
 * @method static Builder<static>|VehicleDocument whereType($value)
 * @method static Builder<static>|VehicleDocument whereUpdatedAt($value)
 * @method static Builder<static>|VehicleDocument whereVehicleId($value)
 * @method static Builder<static>|VehicleDocument withTrashed(bool $withTrashed = true)
 * @method static Builder<static>|VehicleDocument withoutTrashed()
 *
 * @mixin \Eloquent
 */
class VehicleDocument extends Model
{
    use SoftDeletes;

    protected $fillable = ['vehicle_id', 'type', 'number', 'issued_on', 'expires_on', 'file_path', 'notes'];

    protected function casts(): array
    {
        return ['issued_on' => 'date', 'expires_on' => 'date'];
    }

    public function scopeExpiringWithin(Builder $query, int $days): Builder
    {
        return $query->whereNotNull('expires_on')
            ->whereBetween('expires_on', [now()->toDateString(), now()->addDays($days)->toDateString()]);
    }

    public function isExpired(): bool
    {
        return $this->expires_on !== null && $this->expires_on->isPast();
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }
}
