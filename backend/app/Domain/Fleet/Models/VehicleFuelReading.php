<?php

declare(strict_types=1);

namespace App\Domain\Fleet\Models;

use App\Domain\Expense\Models\FuelPurchase;
use App\Domain\User\Models\User;
use App\Domain\Vehicle\Models\Vehicle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One sample of how much fuel a vehicle's tank held at a moment in time.
 *
 * Distinct from FuelPurchase, which records a commercial transaction. A refill
 * produces both: a purchase (money) and a reading (level). Everything else —
 * consumption, a siphon, a sensor glitch — produces only a reading.
 *
 * Readings are immutable except for `delta_pct`, which FuelLevelService may
 * restate on the immediate successor when a late reading lands between two
 * existing ones.
 *
 * @property int $id
 * @property int $vehicle_id
 * @property float $fuel_pct
 * @property float|null $fuel_litres
 * @property float|null $delta_pct
 * @property string $source
 * @property int|null $fuel_purchase_id
 * @property int|null $recorded_by
 * @property Carbon $recorded_at
 * @property Carbon|null $created_at
 * @property-read FuelPurchase|null $purchase
 * @property-read User|null $recorder
 * @property-read Vehicle|null $vehicle
 *
 * @method static Builder<static>|VehicleFuelReading newModelQuery()
 * @method static Builder<static>|VehicleFuelReading newQuery()
 * @method static Builder<static>|VehicleFuelReading query()
 * @method static Builder<static>|VehicleFuelReading simulated()
 *
 * @mixin \Eloquent
 */
class VehicleFuelReading extends Model
{
    /** Readings carry only a creation stamp, as odometer_readings do. */
    public const UPDATED_AT = null;

    /**
     * Where a reading came from.
     *
     * The vocabulary is inherited rather than invented. `database/schema.sql`
     * already defines `chk_odo_source` for the sibling column on
     * `odometer_readings` as ('manual','fuel_log','telematics','ocr'), so
     * `manual` and `telematics` are the platform's existing words for "a person
     * typed it" and "a device reported it". Only `simulated` is new, and it
     * exists because there is no hardware yet and demo data must be separable
     * from real data.
     *
     * `fuel_log` and `ocr` are deliberately absent: both describe ways an
     * *odometer* figure is obtained and neither can produce a tank level.
     */
    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_SIMULATED = 'simulated';

    /** Reserved for real hardware. Nothing writes this yet — see Phase 5. */
    public const SOURCE_TELEMATICS = 'telematics';

    /** @var list<string> */
    public const SOURCES = [
        self::SOURCE_MANUAL,
        self::SOURCE_SIMULATED,
        self::SOURCE_TELEMATICS,
    ];

    protected $fillable = [
        'vehicle_id', 'fuel_pct', 'fuel_litres', 'delta_pct',
        'source', 'fuel_purchase_id', 'recorded_by', 'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'fuel_pct' => 'float',
            'fuel_litres' => 'float',
            'delta_pct' => 'float',
            'recorded_at' => 'datetime',
        ];
    }

    public function scopeSimulated(Builder $query): Builder
    {
        return $query->where('source', self::SOURCE_SIMULATED);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(FuelPurchase::class, 'fuel_purchase_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
