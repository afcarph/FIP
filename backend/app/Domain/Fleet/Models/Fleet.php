<?php

declare(strict_types=1);

namespace App\Domain\Fleet\Models;

use App\Domain\Expense\Models\FuelPurchase;
use App\Domain\Expense\Models\Trip;
use App\Domain\Station\Models\City;
use App\Domain\User\Models\Company;
use App\Domain\User\Models\User;
use App\Domain\Vehicle\Models\Vehicle;
use App\Support\Concerns\Auditable;
use App\Support\Concerns\HasCompanyScope;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $company_id
 * @property string $name
 * @property string|null $code
 * @property int|null $manager_id
 * @property int|null $base_city_id
 * @property numeric|null $monthly_fuel_budget
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read City|null $baseCity
 * @property-read Company|null $company
 * @property-read Collection<int, Driver> $drivers
 * @property-read int|null $drivers_count
 * @property-read Collection<int, FuelPurchase> $fuelPurchases
 * @property-read int|null $fuel_purchases_count
 * @property-read User|null $manager
 * @property-read Collection<int, Trip> $trips
 * @property-read int|null $trips_count
 * @property-read Collection<int, Vehicle> $vehicles
 * @property-read int|null $vehicles_count
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Fleet forUser(?\App\Domain\User\Models\User $user)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Fleet newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Fleet newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Fleet onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Fleet query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Fleet whereBaseCityId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Fleet whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Fleet whereCompanyId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Fleet whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Fleet whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Fleet whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Fleet whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Fleet whereManagerId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Fleet whereMonthlyFuelBudget($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Fleet whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Fleet whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Fleet withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Fleet withoutTrashed()
 *
 * @mixin \Eloquent
 */
class Fleet extends Model
{
    use Auditable;
    use HasCompanyScope;
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'company_id', 'name', 'code', 'manager_id', 'base_city_id', 'monthly_fuel_budget', 'is_active',
    ];

    protected function casts(): array
    {
        return ['monthly_fuel_budget' => 'decimal:2', 'is_active' => 'boolean'];
    }

    /** Spend against the monthly budget, as a percentage. */
    protected function ownerColumn(): ?string
    {
        return 'fleets.manager_id';
    }

    public function budgetUtilisation(?\DateTimeInterface $month = null): ?float
    {
        if (! $this->monthly_fuel_budget) {
            return null;
        }

        $period = $month ? Carbon::instance($month) : now();

        $spend = $this->fuelPurchases()
            ->whereBetween('purchased_at', [$period->copy()->startOfMonth(), $period->copy()->endOfMonth()])
            ->sum('total_cost');

        return round(((float) $spend / (float) $this->monthly_fuel_budget) * 100, 1);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function baseCity(): BelongsTo
    {
        return $this->belongsTo(City::class, 'base_city_id');
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }

    public function drivers(): HasMany
    {
        return $this->hasMany(Driver::class);
    }

    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class);
    }

    public function fuelPurchases(): HasManyThrough
    {
        return $this->hasManyThrough(FuelPurchase::class, Vehicle::class);
    }
}
