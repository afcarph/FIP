<?php

declare(strict_types=1);

namespace App\Domain\Maintenance\Models;

use App\Domain\User\Models\User;
use App\Domain\Vehicle\Models\Vehicle;
use App\Support\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $vehicle_id
 * @property int $maintenance_type_id
 * @property Carbon $performed_at
 * @property float|null $odometer
 * @property float|null $cost
 * @property string|null $vendor
 * @property string|null $invoice_path
 * @property string|null $notes
 * @property int|null $recorded_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read User|null $recorder
 * @property-read MaintenanceType $type
 * @property-read Vehicle|null $vehicle
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MaintenanceRecord newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MaintenanceRecord newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MaintenanceRecord onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MaintenanceRecord query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MaintenanceRecord whereCost($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MaintenanceRecord whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MaintenanceRecord whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MaintenanceRecord whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MaintenanceRecord whereInvoicePath($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MaintenanceRecord whereMaintenanceTypeId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MaintenanceRecord whereNotes($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MaintenanceRecord whereOdometer($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MaintenanceRecord wherePerformedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MaintenanceRecord whereRecordedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MaintenanceRecord whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MaintenanceRecord whereVehicleId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MaintenanceRecord whereVendor($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MaintenanceRecord withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MaintenanceRecord withoutTrashed()
 *
 * @mixin \Eloquent
 */
class MaintenanceRecord extends Model
{
    use Auditable;
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'vehicle_id', 'maintenance_type_id', 'performed_at', 'odometer',
        'cost', 'vendor', 'invoice_path', 'notes', 'recorded_by',
    ];

    protected function casts(): array
    {
        return ['performed_at' => 'date', 'odometer' => 'float', 'cost' => 'float'];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(MaintenanceType::class, 'maintenance_type_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
