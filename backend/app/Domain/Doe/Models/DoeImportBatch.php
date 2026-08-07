<?php

declare(strict_types=1);

namespace App\Domain\Doe\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One capture from the DOE dashboard, with the payload that produced it.
 *
 * The payload is kept so an import can be replayed. That is not only for
 * failures: a parser fix should be re-runnable against the batches it would
 * have got wrong, and by then the dashboard shows a different week.
 *
 * @property int $id
 * @property Carbon $started_at
 * @property Carbon|null $finished_at
 * @property string $status
 * @property string $payload_hash
 * @property string|null $raw_payload
 * @property string|null $error
 * @property int $records_parsed
 * @property int $records_imported
 * @property int $records_skipped
 * @property int $stations_unmatched
 * @property string|null $source_url
 * @property string|null $run_id
 */
class DoeImportBatch extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_IMPORTING = 'importing';

    public const STATUS_IMPORTED = 'imported';

    public const STATUS_FAILED = 'failed';

    /** Captured, but identical to a batch already imported. */
    public const STATUS_SKIPPED = 'skipped';

    protected $table = 'doe_import_batches';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'records_parsed' => 'integer',
            'records_imported' => 'integer',
            'records_skipped' => 'integer',
            'stations_unmatched' => 'integer',
        ];
    }

    /** @return HasMany<DoeImportLog, $this> */
    public function logs(): HasMany
    {
        return $this->hasMany(DoeImportLog::class, 'batch_id');
    }

    /**
     * The decoded payload, or null if it will not decode.
     *
     * Returns null rather than throwing: a corrupt payload is a batch that
     * cannot be imported, which the caller already has to handle, and an
     * exception here would take down a command processing a queue of batches.
     *
     * @return array<string, mixed>|null
     */
    public function decodedPayload(): ?array
    {
        if ($this->raw_payload === null || $this->raw_payload === '') {
            return null;
        }

        $decoded = json_decode($this->raw_payload, true);

        return is_array($decoded) ? $decoded : null;
    }

    public function isReplayable(): bool
    {
        return $this->raw_payload !== null && $this->raw_payload !== '';
    }

    public function log(string $message, string $level = 'info', ?array $context = null): DoeImportLog
    {
        return $this->logs()->create([
            'message' => $message,
            'level' => $level,
            'context' => $context,
            'created_at' => now(),
        ]);
    }

    public function markImported(): void
    {
        $this->update(['status' => self::STATUS_IMPORTED, 'finished_at' => now()]);
    }

    public function markFailed(string $error): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'finished_at' => now(),
            // Bounded: a schema change produces one error per record, and a
            // multi-megabyte TEXT write is its own incident.
            'error' => mb_substr($error, 0, 60000),
        ]);
    }
}
