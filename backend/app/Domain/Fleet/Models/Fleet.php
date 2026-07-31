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
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;

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
    public function budgetUtilisation(?\DateTimeInterface $month = null): ?float
    {
        if (! $this->monthly_fuel_budget) {
            return null;
        }

        $period = $month ? \Carbon\Carbon::instance($month) : now();

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
