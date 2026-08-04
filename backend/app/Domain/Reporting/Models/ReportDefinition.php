<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property string $scope
 * @property array<array-key, mixed>|null $default_params
 * @property string|null $required_permission
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, ReportRun> $runs
 * @property-read int|null $runs_count
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReportDefinition newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReportDefinition newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReportDefinition query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReportDefinition whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReportDefinition whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReportDefinition whereDefaultParams($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReportDefinition whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReportDefinition whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReportDefinition whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReportDefinition whereRequiredPermission($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReportDefinition whereScope($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReportDefinition whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class ReportDefinition extends Model
{
    protected $fillable = ['code', 'name', 'description', 'scope', 'default_params', 'required_permission'];

    protected function casts(): array
    {
        return ['default_params' => 'array'];
    }

    public function runs(): HasMany
    {
        return $this->hasMany(ReportRun::class);
    }
}
