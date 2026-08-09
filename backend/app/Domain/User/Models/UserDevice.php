<?php

declare(strict_types=1);

namespace App\Domain\User\Models;

use App\Domain\Fleet\Models\DeviceLocation;
use App\Domain\Vehicle\Models\Vehicle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $vehicle_id
 * @property string $device_uuid
 * @property string|null $device_name
 * @property string $platform
 * @property string|null $app_version
 * @property string|null $os_version
 * @property string|null $fcm_token
 * @property string|null $biometric_key
 * @property Carbon|null $last_seen_at
 * @property bool $is_trusted
 * @property Carbon|null $revoked_at
 * @property int|null $revoked_by
 * @property float|null $last_latitude
 * @property float|null $last_longitude
 * @property Carbon|null $last_location_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 * @property-read Vehicle|null $vehicle
 * @property-read User|null $revoker
 * @property-read Collection<int, DeviceLocation> $locations
 * @property-read int|null $locations_count
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserDevice active()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserDevice revoked()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserDevice forUser(?User $user)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserDevice newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserDevice newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserDevice pushable()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserDevice query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserDevice whereBiometricKey($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserDevice whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserDevice whereDeviceName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserDevice whereDeviceUuid($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserDevice whereFcmToken($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserDevice whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserDevice whereIsTrusted($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserDevice whereLastSeenAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserDevice wherePlatform($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserDevice whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserDevice whereUserId($value)
 *
 * @mixin \Eloquent
 */
class UserDevice extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'vehicle_id', 'device_uuid', 'device_name', 'platform',
        'app_version', 'os_version', 'fcm_token', 'biometric_key',
        'last_seen_at', 'is_trusted',
    ];

    /*
     * Deliberately absent from $fillable: revoked_at, revoked_by and the
     * last_* location cache. Revocation is a security decision that goes
     * through revoke(), and the cache belongs to the ingestion service — both
     * would be assignable from a request payload otherwise.
     */

    protected $hidden = ['biometric_key'];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
            'is_trusted' => 'boolean',
            'revoked_at' => 'datetime',
            'last_latitude' => 'float',
            'last_longitude' => 'float',
            'last_location_at' => 'datetime',
        ];
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /** A device may report only while registered to a vehicle and not revoked. */
    public function canReportLocation(): bool
    {
        return ! $this->isRevoked() && $this->vehicle_id !== null;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }

    public function scopeRevoked(Builder $query): Builder
    {
        return $query->whereNotNull('revoked_at');
    }

    /**
     * Devices the given user may administer.
     *
     * A device belongs to a person, so this is ownership rather than tenancy:
     * a fleet manager administers the vehicles, not their drivers' handsets.
     * Platform administrators see everything.
     */
    public function scopeForUser(Builder $query, ?User $user): Builder
    {
        if ($user === null) {
            return $query->whereRaw('1 = 0');
        }

        return $user->isPlatformAdministrator()
            ? $query
            : $query->where('user_devices.user_id', $user->getKey());
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopePushable(Builder $query): Builder
    {
        return $query->whereNotNull('fcm_token');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function locations(): HasMany
    {
        return $this->hasMany(DeviceLocation::class, 'device_id');
    }

    public function revoker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }
}
