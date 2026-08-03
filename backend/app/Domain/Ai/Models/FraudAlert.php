<?php

declare(strict_types=1);

namespace App\Domain\Ai\Models;

use App\Domain\Expense\Models\FuelPurchase;
use App\Domain\Fleet\Models\Driver;
use App\Domain\Fleet\Models\Fleet;
use App\Domain\User\Models\Company;
use App\Domain\User\Models\User;
use App\Domain\Vehicle\Models\Vehicle;
use App\Support\Casts\NumericJson;
use App\Support\Concerns\Auditable;
use App\Support\Concerns\HasCompanyScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FraudAlert extends Model
{
    use Auditable;
    use HasCompanyScope;
    use HasFactory;

    public const STATUS_OPEN = 'open';

    public const STATUS_INVESTIGATING = 'investigating';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_DISMISSED = 'dismissed';

    protected $fillable = [
        'company_id', 'fleet_id', 'vehicle_id', 'driver_id', 'fuel_purchase_id',
        'alert_type', 'severity', 'score', 'evidence', 'status',
        'resolved_by', 'resolved_at', 'resolution_note', 'detected_at',
    ];

    protected function casts(): array
    {
        return [
            // Evidence carries measured quantities (litres, prices, deltas), so
            // it needs a cast that does not flatten 85.0 into 85.
            'evidence' => NumericJson::class,
            'score' => 'float',
            'detected_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function scopeUnresolved(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_OPEN, self::STATUS_INVESTIGATING]);
    }

    /** Map a raw anomaly score onto the configured severity bands. */
    public static function severityForScore(float $score): string
    {
        $bands = config('fip.fraud.severity_bands');
        arsort($bands);

        foreach ($bands as $severity => $floor) {
            if ($score >= $floor) {
                return $severity;
            }
        }

        return 'low';
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function fleet(): BelongsTo
    {
        return $this->belongsTo(Fleet::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(FuelPurchase::class, 'fuel_purchase_id');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
