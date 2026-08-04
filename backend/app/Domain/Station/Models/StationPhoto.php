<?php

declare(strict_types=1);

namespace App\Domain\Station\Models;

use App\Domain\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property int $station_id
 * @property int|null $user_id
 * @property string $path
 * @property string|null $caption
 * @property bool $is_primary
 * @property string $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read string|null $url
 * @property-read GasStation|null $station
 * @property-read User|null $uploader
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StationPhoto newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StationPhoto newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StationPhoto onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StationPhoto query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StationPhoto whereCaption($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StationPhoto whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StationPhoto whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StationPhoto whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StationPhoto whereIsPrimary($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StationPhoto wherePath($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StationPhoto whereStationId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StationPhoto whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StationPhoto whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StationPhoto whereUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StationPhoto withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StationPhoto withoutTrashed()
 *
 * @mixin \Eloquent
 */
class StationPhoto extends Model
{
    use SoftDeletes;

    protected $fillable = ['station_id', 'user_id', 'path', 'caption', 'is_primary', 'status'];

    protected function casts(): array
    {
        return ['is_primary' => 'boolean'];
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(GasStation::class, 'station_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** Signed URL so private bucket objects stay private. */
    public function getUrlAttribute(): ?string
    {
        return $this->path === null ? null : Storage::temporaryUrl($this->path, now()->addHour());
    }
}
