<?php

declare(strict_types=1);

namespace App\Domain\User\Models;

use App\Domain\Fleet\Models\Driver;
use App\Domain\Fleet\Models\Fleet;
use App\Domain\Station\Models\City;
use App\Domain\Station\Models\GasStation;
use App\Domain\Vehicle\Models\Vehicle;
use App\Support\Concerns\Auditable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string|null $legal_name
 * @property string|null $tin
 * @property string|null $industry
 * @property string $type
 * @property string|null $address_line
 * @property int|null $city_id
 * @property string|null $contact_email
 * @property string|null $contact_phone
 * @property string|null $logo_path
 * @property string $subscription_tier
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read City|null $city
 * @property-read Collection<int, Driver> $drivers
 * @property-read int|null $drivers_count
 * @property-read Collection<int, Fleet> $fleets
 * @property-read int|null $fleets_count
 * @property-read Collection<int, GasStation> $stations
 * @property-read int|null $stations_count
 * @property-read Collection<int, User> $users
 * @property-read int|null $users_count
 * @property-read Collection<int, Vehicle> $vehicles
 * @property-read int|null $vehicles_count
 *
 * @method static \Database\Factories\CompanyFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company whereAddressLine($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company whereCityId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company whereContactEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company whereContactPhone($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company whereIndustry($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company whereLegalName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company whereLogoPath($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company whereSubscriptionTier($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company whereTin($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company withoutTrashed()
 *
 * @mixin \Eloquent
 */
class Company extends Model
{
    use Auditable;
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'name', 'legal_name', 'tin', 'industry', 'type', 'address_line', 'city_id',
        'contact_email', 'contact_phone', 'logo_path', 'subscription_tier', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function fleets(): HasMany
    {
        return $this->hasMany(Fleet::class);
    }

    public function drivers(): HasMany
    {
        return $this->hasMany(Driver::class);
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }

    public function stations(): HasMany
    {
        return $this->hasMany(GasStation::class, 'operator_id');
    }
}
