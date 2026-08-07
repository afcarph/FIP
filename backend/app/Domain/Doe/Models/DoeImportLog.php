<?php

declare(strict_types=1);

namespace App\Domain\Doe\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A line in a batch's log.
 *
 * @property int $id
 * @property int $batch_id
 * @property string $message
 * @property string $level
 * @property array<string, mixed>|null $context
 * @property Carbon|null $created_at
 */
class DoeImportLog extends Model
{
    protected $table = 'doe_import_logs';

    protected $guarded = ['id'];

    /**
     * The table has `created_at` and no `updated_at` — a log line is written
     * once and never edited, so Eloquent's pair does not fit it.
     */
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<DoeImportBatch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(DoeImportBatch::class, 'batch_id');
    }
}
