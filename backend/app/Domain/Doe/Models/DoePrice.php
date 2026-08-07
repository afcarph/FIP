<?php

declare(strict_types=1);

namespace App\Domain\Doe\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One station's posted prices on one date, from the scraper's database.
 *
 * The grain is the DOE's own: all six grades on one row, because that is how a
 * station reports. The platform's own `fuel_price_history` is one row per fuel
 * type — a different table in a different database, and not this one.
 *
 * @property int $id
 * @property int $station_id
 * @property Carbon $price_date
 * @property float|null $ron91
 * @property float|null $ron95
 * @property float|null $ron97
 * @property float|null $ron100
 * @property float|null $diesel
 * @property float|null $diesel_plus
 * @property Carbon|null $scraped_at
 * @property Carbon|null $updated_at
 */
class DoePrice extends Model
{
    protected $connection = 'doe';

    protected $table = 'fuel_price_history';

    protected $guarded = ['id'];

    /**
     * The scraper's table has `scraped_at` and `updated_at` but no
     * `created_at` — a price row is an observation, and when we first saw it is
     * `scraped_at`. Eloquent's timestamp pair does not fit that, and leaving it
     * on makes every insert name a column the table does not have.
     *
     * Laravel does not write here in production regardless: the scraper owns
     * this table.
     */
    public $timestamps = false;

    /**
     * The price columns, in the order the DOE lists them.
     *
     * Every query that accepts a fuel type validates against this list. It is
     * the allow-list that keeps a user-supplied `fuel` parameter out of a raw
     * column name — see FuelQueryService::assertFuel.
     *
     * @var list<string>
     */
    public const FUELS = ['ron91', 'ron95', 'ron97', 'ron100', 'diesel', 'diesel_plus'];

    /**
     * Human labels, for responses. Kept beside the column list so a new grade
     * cannot be added to one without the other.
     *
     * @var array<string, string>
     */
    public const FUEL_LABELS = [
        'ron91' => 'Gasoline RON 91',
        'ron95' => 'Gasoline RON 95',
        'ron97' => 'Gasoline RON 97',
        'ron100' => 'Gasoline RON 100',
        'diesel' => 'Diesel',
        'diesel_plus' => 'Diesel Plus',
    ];

    protected function casts(): array
    {
        return [
            'price_date' => 'date',
            'ron91' => 'float',
            'ron95' => 'float',
            'ron97' => 'float',
            'ron100' => 'float',
            'diesel' => 'float',
            'diesel_plus' => 'float',
            'scraped_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<DoeStation, $this> */
    public function station(): BelongsTo
    {
        return $this->belongsTo(DoeStation::class, 'station_id');
    }

    /**
     * The grades this row actually carries.
     *
     * @return array<string, float>
     */
    public function pricedFuels(): array
    {
        $prices = [];

        foreach (self::FUELS as $fuel) {
            if ($this->{$fuel} !== null) {
                $prices[$fuel] = (float) $this->{$fuel};
            }
        }

        return $prices;
    }
}
