<?php

declare(strict_types=1);

namespace App\Domain\Doe\Models;

use App\Domain\Station\Models\GasStation;
use App\Domain\User\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A DOE listing the matcher could not resolve, queued for a human.
 *
 * Keyed on a fingerprint of the listing so the same unmatched station across
 * thirty daily runs is one row, not thirty. `times_seen` is what makes the
 * queue triageable: a station appearing every day matters more than one that
 * appeared once and never returned.
 *
 * @property int $id
 * @property string|null $doe_company
 * @property string|null $doe_station
 * @property string|null $doe_address
 * @property string|null $doe_city
 * @property string|null $doe_province
 * @property string|null $doe_barangay
 * @property float|null $doe_latitude
 * @property float|null $doe_longitude
 * @property string $fingerprint
 * @property int|null $suggested_station_id
 * @property float|null $suggested_confidence
 * @property string|null $suggested_strategy
 * @property int|null $resolved_station_id
 * @property int|null $resolved_by
 * @property Carbon|null $resolved_at
 * @property string $status
 * @property string|null $notes
 * @property int $times_seen
 * @property Carbon|null $last_seen_at
 */
class DoeStationReview extends Model
{
    public const STATUS_PENDING = 'pending';

    /** A reviewer pointed this listing at a station. */
    public const STATUS_MAPPED = 'mapped';

    /**
     * Deliberately not a station: a depot, a closed site, a duplicate listing.
     * Without this the same row resurfaces in the queue every single day.
     */
    public const STATUS_IGNORED = 'ignored';

    protected $table = 'doe_station_review';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'doe_latitude' => 'float',
            'doe_longitude' => 'float',
            'suggested_confidence' => 'float',
            'resolved_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'times_seen' => 'integer',
        ];
    }

    /** @return BelongsTo<GasStation, $this> */
    public function suggestedStation(): BelongsTo
    {
        return $this->belongsTo(GasStation::class, 'suggested_station_id');
    }

    /** @return BelongsTo<GasStation, $this> */
    public function resolvedStation(): BelongsTo
    {
        return $this->belongsTo(GasStation::class, 'resolved_station_id');
    }

    /** @return BelongsTo<User, $this> */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /** @param Builder<DoeStationReview> $query */
    public function scopePending(Builder $query): void
    {
        $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Listings a reviewer has mapped, which the matcher consults first.
     *
     * @param Builder<DoeStationReview> $query
     */
    public function scopeMapped(Builder $query): void
    {
        $query->where('status', self::STATUS_MAPPED)->whereNotNull('resolved_station_id');
    }

    /**
     * Identity of a DOE listing.
     *
     * Company, station, city and barangay together — the same four the review
     * queue is keyed on. Normalised for case and whitespace because the feed is
     * inconsistent about both between weeks, and a fingerprint that changed
     * with capitalisation would re-queue a station a reviewer had already
     * mapped.
     */
    public static function fingerprintFor(
        ?string $company,
        ?string $station,
        ?string $city,
        ?string $barangay = null,
    ): string {
        $parts = array_map(
            static fn (?string $part): string => mb_strtolower(trim((string) $part)),
            [$company, $station, $city, $barangay],
        );

        return hash('sha256', implode('|', $parts));
    }
}
