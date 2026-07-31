<?php

declare(strict_types=1);

namespace App\Domain\Station\Models;

use App\Domain\Expense\Models\FuelPurchase;
use App\Domain\Pricing\Models\OcrScan;
use App\Domain\Pricing\Models\PriceReport;
use App\Domain\Pricing\Models\StationPrice;
use App\Domain\User\Models\Company;
use App\Domain\User\Models\User;
use App\Support\Concerns\Auditable;
use App\Support\Concerns\GeoDistance;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A physical retail fuel outlet.
 *
 * @property float $latitude
 * @property float $longitude
 */
class GasStation extends Model
{
    use Auditable;
    use GeoDistance;
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'brand_id', 'operator_id', 'managed_by', 'name', 'slug', 'address_line',
        'city_id', 'postal_code', 'latitude', 'longitude', 'phone', 'is_24_hours',
        'has_ev_charging', 'status', 'verified_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'is_24_hours' => 'boolean',
            'has_ev_charging' => 'boolean',
            'verified_at' => 'datetime',
            'rating_avg' => 'float',
            'rating_count' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $station): void {
            $station->slug ??= static::uniqueSlug($station->name);
        });
    }

    private static function uniqueSlug(string $name): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $n = 1;

        while (static::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$n);
        }

        return $slug;
    }

    // ------------------------------------------------------------ Scopes ---

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    /**
     * Radius search. A bounding-box predicate runs first so InnoDB can prune
     * with the lat/lng index, then ST_Distance_Sphere gives the exact figure;
     * the computed distance is exposed as `distance_m`.
     */
    public function scopeWithinRadius(Builder $query, float $lat, float $lng, float $radiusKm): Builder
    {
        $box = (new static)->boundingBox($lat, $lng, $radiusKm);

        return $query
            ->whereBetween('latitude', [$box['min_lat'], $box['max_lat']])
            ->whereBetween('longitude', [$box['min_lng'], $box['max_lng']])
            ->selectRaw(
                'gas_stations.*, ST_Distance_Sphere(location, ST_SRID(POINT(?, ?), 4326)) AS distance_m',
                [$lng, $lat],
            )
            ->having('distance_m', '<=', $radiusKm * 1000)
            ->orderBy('distance_m');
    }

    public function scopeSellingFuel(Builder $query, int $fuelTypeId): Builder
    {
        return $query->whereHas('prices', fn (Builder $q) => $q->where('fuel_type_id', $fuelTypeId));
    }

    // ------------------------------------------------------------ Domain ---

    public function priceFor(int $fuelTypeId): ?StationPrice
    {
        return $this->prices->firstWhere('fuel_type_id', $fuelTypeId);
    }

    public function recalculateRating(): void
    {
        $stats = $this->ratings()
            ->selectRaw('AVG(rating) AS avg_rating, COUNT(*) AS total')
            ->first();

        $this->forceFill([
            'rating_avg' => round((float) ($stats->avg_rating ?? 0), 2),
            'rating_count' => (int) ($stats->total ?? 0),
        ])->saveQuietly();
    }

    public function isOpenAt(\DateTimeInterface $moment): bool
    {
        if ($this->is_24_hours) {
            return $this->status === 'active';
        }

        $hours = $this->hours->firstWhere('day_of_week', (int) $moment->format('w'));

        if ($hours === null || $hours->is_closed) {
            return false;
        }

        $time = $moment->format('H:i:s');

        return $time >= $hours->opens_at && $time <= $hours->closes_at;
    }

    // ----------------------------------------------------- Relationships ---

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'operator_id');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'managed_by');
    }

    public function prices(): HasMany
    {
        return $this->hasMany(StationPrice::class, 'station_id');
    }

    public function priceReports(): HasMany
    {
        return $this->hasMany(PriceReport::class, 'station_id');
    }

    public function ocrScans(): HasMany
    {
        return $this->hasMany(OcrScan::class, 'station_id');
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(FuelPurchase::class, 'station_id');
    }

    public function hours(): HasMany
    {
        return $this->hasMany(StationHour::class, 'station_id');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(StationPhoto::class, 'station_id');
    }

    public function ratings(): HasMany
    {
        return $this->hasMany(StationRating::class, 'station_id');
    }

    public function amenities(): BelongsToMany
    {
        return $this->belongsToMany(Amenity::class, 'station_amenity', 'station_id', 'amenity_id');
    }

    public function paymentMethods(): BelongsToMany
    {
        return $this->belongsToMany(PaymentMethod::class, 'station_payment_method', 'station_id', 'payment_method_id');
    }
}
