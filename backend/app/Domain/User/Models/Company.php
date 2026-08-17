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
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
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
 * @property string $subscription_status
 * @property Carbon|null $trial_started_at
 * @property Carbon|null $trial_ends_at
 * @property array<string, int|null>|null $subscription_limits
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

    /**
     * Capacity for this company, when a caller has already worked it out.
     *
     * Declared rather than assigned dynamically: an undeclared assignment on a
     * model becomes an *attribute*, which would put a computed report into the
     * things Eloquent thinks it should save.
     *
     * @var array<string, mixed>|null
     */
    public ?array $subscriptionReport = null;

    use SoftDeletes;

    protected $fillable = [
        'name', 'legal_name', 'tin', 'industry', 'type', 'address_line', 'city_id',
        'contact_email', 'contact_phone', 'logo_path', 'subscription_tier', 'is_active',
        'subscription_status', 'trial_started_at', 'trial_ends_at', 'subscription_limits',
    ];

    /** Trialing until it lapses; active once paying; expired when it lapses. */
    public const STATUS_TRIALING = 'trialing';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_EXPIRED = 'expired';

    /**
     * Chosen, but not yet honoured.
     *
     * Enterprise limits are negotiated, so a stranger selecting that plan at
     * registration must not be handed them. The company exists and works; it
     * runs on the default plan's allowance until a platform administrator
     * confirms what was actually agreed.
     */
    public const STATUS_PENDING_SETUP = 'pending_setup';

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'trial_started_at' => 'datetime',
            'trial_ends_at' => 'datetime',
            'subscription_limits' => 'array',
        ];
    }

    /** A trial that has run out. Says nothing about what should happen next. */
    public function trialHasExpired(): bool
    {
        return $this->trial_ends_at !== null && $this->trial_ends_at->isPast();
    }

    /**
     * The plan whose limits actually apply.
     *
     * Not always the plan on the record. An enterprise selection awaiting
     * confirmation cannot simply be granted its negotiated limits — that would
     * hand unlimited capacity to whoever typed the company name — so it runs
     * on a bounded interim allowance until somebody confirms what was agreed.
     *
     * Deliberately not the default plan. That is the smallest allowance in the
     * product, and showing a prospect who chose Enterprise a limit of three
     * vehicles both reads as an insult and blocks a genuine evaluation on its
     * first afternoon. See `subscription.pending_tier`.
     */
    public function effectiveTier(): string
    {
        if ($this->subscription_status === self::STATUS_PENDING_SETUP) {
            return (string) config('fip.subscription.pending_tier', config('fip.subscription.default_tier'));
        }

        return (string) ($this->subscription_tier ?? config('fip.subscription.default_tier'));
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

    /**
     * Every device registered to somebody in this company.
     *
     * Devices hang off users rather than off the company, so counting them
     * needs the hop. Kept as a relation so the administration listing can
     * count them in its own query rather than once per row.
     */
    public function devices(): HasManyThrough
    {
        return $this->hasManyThrough(UserDevice::class, User::class);
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
