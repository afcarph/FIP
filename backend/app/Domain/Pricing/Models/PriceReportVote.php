<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Models;

use App\Domain\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $price_report_id
 * @property int $user_id
 * @property int $vote
 * @property Carbon|null $created_at
 * @property-read PriceReport|null $report
 * @property-read User|null $user
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceReportVote newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceReportVote newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceReportVote query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceReportVote whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceReportVote whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceReportVote wherePriceReportId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceReportVote whereUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceReportVote whereVote($value)
 *
 * @mixin \Eloquent
 */
class PriceReportVote extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['price_report_id', 'user_id', 'vote'];

    protected function casts(): array
    {
        return ['vote' => 'integer'];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(PriceReport::class, 'price_report_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
