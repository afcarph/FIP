<?php

declare(strict_types=1);

namespace App\Domain\Doe\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A station as the DOE publishes it, read from the scraper's database.
 *
 * This is deliberately *not* App\Domain\Station\Models\GasStation. That model
 * is the platform's own directory — operator-managed, with amenities, ratings
 * and photos. This one is an observation: whatever the DOE said, unedited.
 * Conflating them would mean a scraper run could silently rewrite a station an
 * operator maintains.
 *
 * There is no factory and no migration here because the scraper owns the
 * schema. Laravel reads this table; it never creates or alters it.
 *
 * @property int $id
 * @property string $company
 * @property string|null $name
 * @property string|null $region
 * @property string|null $province
 * @property string|null $city
 * @property string|null $barangay
 * @property string|null $address
 * @property float|null $latitude
 * @property float|null $longitude
 * @property Carbon|null $first_seen_at
 * @property Carbon|null $last_seen_at
 */
class DoeStation extends Model
{
    use HasFactory;

    protected $connection = 'doe';

    protected $table = 'fuel_stations';

    /**
     * Guarded rather than fillable, and nothing in the app mass-assigns to it
     * anyway — writes belong to the scraper.
     */
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /** @return HasMany<DoePrice, $this> */
    public function prices(): HasMany
    {
        return $this->hasMany(DoePrice::class, 'station_id');
    }

    /** @return HasMany<DoePrice, $this> */
    public function latestPrice(): HasMany
    {
        return $this->prices()->orderByDesc('price_date')->limit(1);
    }

    /**
     * A display label, since the DOE does not always publish a station name.
     */
    public function getLabelAttribute(): string
    {
        return trim($this->name ?: $this->company);
    }

    /**
     * The address the DOE published, or one assembled from the geography it
     * did publish. A station with no address at all reads as a data error to a
     * user, when in fact the DOE simply lists it by barangay.
     */
    public function getDisplayAddressAttribute(): string
    {
        if ($this->address) {
            return $this->address;
        }

        return implode(', ', array_filter([
            $this->barangay,
            $this->city,
            $this->province,
        ])) ?: 'Address not published';
    }
}
