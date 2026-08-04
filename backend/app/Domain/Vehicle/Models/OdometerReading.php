<?php

declare(strict_types=1);

namespace App\Domain\Vehicle\Models;

use App\Domain\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $vehicle_id
 * @property numeric $reading
 * @property string $source
 * @property Carbon $recorded_at
 * @property int|null $recorded_by
 * @property string|null $photo_path
 * @property Carbon|null $created_at
 * @property-read User|null $recorder
 * @property-read Vehicle|null $vehicle
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OdometerReading newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OdometerReading newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OdometerReading query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OdometerReading whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OdometerReading whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OdometerReading wherePhotoPath($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OdometerReading whereReading($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OdometerReading whereRecordedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OdometerReading whereRecordedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OdometerReading whereSource($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OdometerReading whereVehicleId($value)
 *
 * @mixin \Eloquent
 */
class OdometerReading extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['vehicle_id', 'reading', 'source', 'recorded_at', 'recorded_by', 'photo_path'];

    protected function casts(): array
    {
        return ['reading' => 'decimal:2', 'recorded_at' => 'datetime'];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
