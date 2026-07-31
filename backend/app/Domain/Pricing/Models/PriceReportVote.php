<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Models;

use App\Domain\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
