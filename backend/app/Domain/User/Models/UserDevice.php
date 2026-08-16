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
 * @property int|null $battery_percentage
 * @property string|null $battery_state
 * @property Carbon|null $battery_updated_at
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

    /**
     * The platforms a subscription's device allowance is about.
     *
     * A plan's "devices" are the handsets that ride in vehicles and report
     * position. A browser is not one: signing in on the web creates a
     * registration too, because the app needs an identity to log out and to
     * stamp on requests, but it can never be attached to a vehicle and has
     * never reported a fix.
     */
    public const PLAN_PLATFORMS = ['ios', 'android'];

    protected $fillable = [
        'user_id', 'vehicle_id', 'device_uuid', 'device_name', 'platform',
        'app_version', 'os_version', 'fcm_token', 'biometric_key',
        'last_seen_at', 'is_trusted',
    ];

    /*
     * Deliberately absent from $fillable: revoked_at, revoked_by, the last_*
     * location cache and the battery_* health cache. Revocation is a security
     * decision that goes through revoke(), and the two caches belong to the
     * services that own them — all would be assignable from a request payload
     * otherwise. Battery in particular is written only by the device reporting
     * about itself, never by a client editing a device.
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
            'battery_percentage' => 'integer',
            'battery_updated_at' => 'datetime',
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

    /**
     * Whether the battery reading is recent enough to mean anything.
     *
     * A device that stopped reporting still holds the last percentage it sent.
     * Shown unqualified, a phone that died at 4% yesterday looks like a phone
     * on 4% now, and someone goes looking for a van that is simply parked with
     * a flat handset. Past this window the reading is history, not status.
     */
    public function hasFreshBattery(): bool
    {
        return $this->battery_updated_at !== null
            && $this->battery_updated_at->gt(now()->subMinutes(
                (int) config('fip.device_health.battery_stale_after_minutes'),
            ));
    }

    /**
     * Whether the device has reported recently enough to be called online.
     *
     * Deliberately derived from last_seen_at rather than from tracking state:
     * "online" here means the server has heard from it, which is the only thing
     * the server can honestly claim. A device may be online and not tracking.
     */
    /**
     * Whether the last position can still be believed.
     *
     * Same idea as hasFreshBattery: a coordinate with no age beside it is a
     * guess about where a vehicle is now, and an operator acting on a
     * day-old fix would go to the wrong place.
     */
    public function hasFreshLocation(): bool
    {
        return $this->last_location_at !== null
            && $this->last_location_at->gt(
                now()->subMinutes((int) config('fip.device_health.location_stale_after_minutes')),
            );
    }

    public function isOnline(): bool
    {
        return $this->last_seen_at !== null
            && $this->last_seen_at->gt(now()->subMinutes(
                (int) config('fip.device_health.offline_after_minutes'),
            ));
    }

    public function isCharging(): bool
    {
        return in_array($this->battery_state, ['charging', 'full'], true);
    }

    /** Low enough to be worth a fleet operator's attention, and believable. */
    public function hasLowBattery(): bool
    {
        return $this->hasFreshBattery()
            && ! $this->isCharging()
            && $this->battery_percentage !== null
            && $this->battery_percentage <= (int) config('fip.device_health.low_battery_pct');
    }

    /**
     * Registrations that spend a company's device allowance.
     *
     * Counting browsers here let one manager on two laptops exhaust a small
     * tenant's whole allowance before a single driver's phone could register —
     * and the refusal named a limit the drivers had not reached. Enforcement
     * and counting must use this together: refusing something that does not
     * count would be the same mistake pointing the other way.
     */
    public function scopeCountsTowardPlan(Builder $query): Builder
    {
        return $query->whereIn('platform', self::PLAN_PLATFORMS);
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

    /**
     * Devices whose health a fleet operator may see.
     *
     * Narrower than "every device in the company", and deliberately so. The
     * rule elsewhere in this model is that a device belongs to a person, not a
     * tenant — a manager runs the vehicles, not their drivers' handsets. What
     * changes that here is the vehicle association: a phone attached to a
     * company truck is what makes that truck visible on the map, so its charge
     * and its silence are the operator's business.
     *
     * So the association is the whole test. A driver's personal handset with no
     * vehicle on it stays invisible to their manager, exactly as before, and a
     * driver who detaches their phone from the vehicle leaves this view with it.
     *
     * Revoked devices remain listed: "this device was cut off" is precisely the
     * kind of thing a health view exists to show.
     */
    public function scopeForFleetHealth(Builder $query, ?User $user): Builder
    {
        if ($user === null) {
            return $query->whereRaw('1 = 0');
        }

        $query->whereNotNull('user_devices.vehicle_id');

        if ($user->isPlatformAdministrator()) {
            return $query;
        }

        // Fail closed. A user with no company has no fleet, so they see no
        // fleet devices — not every device whose vehicle also has no company.
        if ($user->company_id === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas('vehicle', fn (Builder $vehicle) => $vehicle->where('company_id', $user->company_id));
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
