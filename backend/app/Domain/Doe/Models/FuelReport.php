<?php

declare(strict_types=1);

namespace App\Domain\Doe\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One published DOE price monitoring PDF.
 *
 * Written by the ingest service, read here. Laravel owns the schema and never
 * writes these rows — the split keeps one writer per table.
 *
 * @property int $id
 * @property string $region
 * @property Carbon|null $publication_date
 * @property Carbon $coverage_start
 * @property Carbon $coverage_end
 * @property Carbon|null $monitoring_date
 * @property string|null $source_url
 * @property string $pdf_filename
 * @property string|null $pdf_path
 * @property string $checksum
 * @property string|null $extractor
 * @property float|null $quality
 * @property int $areas_count
 * @property int $rows_count
 */
class FuelReport extends Model
{
    protected $table = 'fuel_reports';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'publication_date' => 'date',
            'coverage_start' => 'date',
            'coverage_end' => 'date',
            'monitoring_date' => 'date',
            'quality' => 'float',
            'areas_count' => 'integer',
            'rows_count' => 'integer',
        ];
    }

    /** @return HasMany<FuelPrice, $this> */
    public function prices(): HasMany
    {
        return $this->hasMany(FuelPrice::class, 'report_id');
    }

    /** @param Builder<FuelReport> $query */
    public function scopeForRegion(Builder $query, string $region): void
    {
        $query->where('region', 'like', '%'.$region.'%');
    }

    /**
     * The most recent report per region.
     *
     * Latest is anchored to the data, not the calendar: a region whose report
     * was not published this week should show last week's figures rather than
     * nothing, which reads as an outage.
     *
     * @param Builder<FuelReport> $query
     */
    public function scopeLatestPerRegion(Builder $query): void
    {
        // Keyed on the coverage week, not on `MAX(id)`.
        //
        // Insertion order is not publication order. Discovery walks the CMS by
        // `dateModified`, which is when a file was last *touched* — the DOE
        // re-uploads older weeks, and a backfill imports oldest-first — so the
        // highest id is routinely an older report. On staging that meant the
        // dashboard headlined the week of 14 July while the week of 4 August
        // sat in the same table.
        //
        // The id is still the tie-break: a week re-issued as a correction has
        // two rows only transiently, and the later one is the correction.
        $query->whereIn('id', function ($sub): void {
            $sub->selectRaw('MAX(id)')
                ->from('fuel_reports as newest')
                ->whereRaw(
                    'newest.coverage_start = ('
                    .'select max(inner_reports.coverage_start) from fuel_reports as inner_reports '
                    .'where inner_reports.region = newest.region)',
                )
                ->groupBy('region');
        });
    }

    public function getCoverageLabelAttribute(): string
    {
        return $this->coverage_start->format('j M').' – '.$this->coverage_end->format('j M Y');
    }
}
