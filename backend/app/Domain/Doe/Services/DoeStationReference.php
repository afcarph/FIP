<?php

declare(strict_types=1);

namespace App\Domain\Doe\Services;

use App\Domain\Doe\Models\FuelPrice;
use App\Domain\Doe\Models\FuelReport;
use App\Domain\Station\Models\GasStation;
use Illuminate\Support\Collection;

/**
 * Whether a station may carry a DOE price, and why not when it may not.
 *
 * The DOE publishes prices per **area, product and brand**. It publishes no
 * station-level data at all — no names, no addresses, no coordinates. So a
 * DOE figure is never that forecourt's price; at best it is the published
 * range for that brand in that municipality during that week.
 *
 * This class decides when the association is safe enough to show against a
 * station, and it fails closed. Anything that does not satisfy every
 * condition is returned as a regional reference with the reason attached, so
 * the UI can be specific instead of silently showing nothing.
 */
final class DoeStationReference
{
    /**
     * Platform region code to DOE region label, where the mapping is 1:1.
     *
     * Deliberately tiny. "REGIONS 6-8" is a *multi-region* label covering
     * Western Visayas, Central Visayas and Eastern Visayas, so it cannot
     * resolve to any single platform region — a station in R6 and a station
     * in R7 would both claim it, and one of them would be wrong. Until the
     * DOE publishes those separately, or a reviewed mapping table exists,
     * nothing joins through it.
     */
    private const REGION_EQUIVALENCE = [
        'NCR' => 'NCR',
    ];

    /**
     * DOE brand heading to platform `brands.code`.
     *
     * Matching on display name fails on exactly the brands whose marketing
     * name differs from the DOE's column heading — "Phoenix Petroleum" against
     * "Phoenix", "TotalEnergies" against "Total" — and those failures are
     * invisible, because the station simply shows no price.
     *
     * Unmapped headings are left unmapped on purpose. "Independent" is not a
     * brand, it is the DOE's column for unbranded stations; "Ptt" has no
     * platform brand record. Guessing either would attach somebody else's
     * prices to a forecourt.
     */
    private const BRAND_EQUIVALENCE = [
        'petron' => 'petron',
        'shell' => 'shell',
        'caltex' => 'caltex',
        'seaoil' => 'seaoil',
        'phoenix' => 'phoenix',
        'total' => 'total',
        'jetti' => 'jetti',
        'unioil' => 'unioil',
        'flying v' => 'flying_v',
        'cleanfuel' => 'cleanfuel',
    ];

    public const REASON_MATCHED = 'matched';

    public const REASON_UNVERIFIED_STATION = 'station_not_verified';

    public const REASON_REGION_UNRESOLVED = 'region_cannot_be_uniquely_resolved';

    public const REASON_NO_REPORT = 'no_doe_report_for_region';

    public const REASON_INVALID_COVERAGE = 'report_has_no_valid_coverage_period';

    public const REASON_AREA_NOT_MONITORED = 'area_not_monitored';

    public const REASON_PROVINCE_MISMATCH = 'province_mismatch';

    public const REASON_BRAND_NOT_PUBLISHED = 'brand_not_published_in_area';

    public const REASON_BRAND_UNKNOWN = 'brand_has_no_doe_equivalent';

    public function __construct(private readonly DoeAreaNormaliser $areas) {}

    /**
     * The reference for one station.
     *
     * @return array<string, mixed>
     */
    public function for(GasStation $station): array
    {
        // 1. The station itself must be verified. An unverified station is one
        //    nobody has confirmed exists, let alone confirmed the brand of.
        if ($station->verified_at === null) {
            return $this->regional(self::REASON_UNVERIFIED_STATION);
        }

        // city_id, province_id, region_id and brand_id are all NOT NULL, so
        // this chain always resolves — nullsafe here would imply a state the
        // schema does not permit.
        $regionCode = $station->city->province->region->code;
        $doeRegion = self::REGION_EQUIVALENCE[$regionCode] ?? null;

        // 2. The region must resolve to exactly one DOE region. A code with no
        //    entry means the department publishes nothing we can attribute to
        //    it — either no report at all, or a label like "REGIONS 6-8" that
        //    covers several regions and so identifies none of them.
        if ($doeRegion === null) {
            return $this->regional(self::REASON_NO_REPORT, ['station_region' => $regionCode]);
        }

        $report = $this->latestReportFor($doeRegion);

        if ($report === null) {
            return $this->regional(self::REASON_NO_REPORT, ['doe_region' => $doeRegion]);
        }

        // 7. The coverage window has to make sense. Both columns are NOT
        //    NULL, so the reachable failure is a window that runs backwards —
        //    which the extractor has produced before, from a range printed
        //    across a year boundary. A price stamped with an impossible week
        //    is one nobody can act on.
        if ($report->coverage_end < $report->coverage_start) {
            return $this->regional(self::REASON_INVALID_COVERAGE, ['doe_region' => $doeRegion]);
        }

        $rows = $this->pricesFor($report);
        $stationArea = $station->city->name;

        $inArea = $rows->filter(
            fn (FuelPrice $row): bool => $this->areas->matches($row->area, $stationArea),
        );

        // 3. The area must be one the DOE actually monitored this week.
        if ($inArea->isEmpty()) {
            return $this->regional(self::REASON_AREA_NOT_MONITORED, [
                'doe_region' => $doeRegion,
                'station_area' => $stationArea,
                'report' => $this->reportPayload($report),
            ]);
        }

        // 5. Where the DOE names a province, it must agree. This is the guard
        //    that stops San Fernando, Cebu attaching to San Fernando,
        //    Pampanga — two real municipalities whose names match exactly.
        $stationProvince = $station->city->province->name;
        $doeProvince = $inArea->first(fn (FuelPrice $row): bool => $row->province !== null)?->province;

        if ($doeProvince !== null && ! $this->areas->matches($doeProvince, $stationProvince)) {
            return $this->regional(self::REASON_PROVINCE_MISMATCH, [
                'doe_region' => $doeRegion,
                'station_province' => $stationProvince,
                'doe_province' => $doeProvince,
            ]);
        }

        // 4. Brand identity comes from the platform's code, never its label.
        $brandCode = $station->brand->code;

        $branded = $inArea->filter(
            fn (FuelPrice $row): bool => $row->brand !== null
                && (self::BRAND_EQUIVALENCE[mb_strtolower(trim($row->brand))] ?? null) === $brandCode,
        );

        // 6. The DOE must actually publish that brand in that area. A brand
        //    with no row here did not report a price; showing the area's
        //    overall range in its place would be attributing somebody else's
        //    figures to it.
        if ($branded->isEmpty()) {
            return $this->regional(self::REASON_BRAND_NOT_PUBLISHED, [
                'doe_region' => $doeRegion,
                'station_area' => $stationArea,
                'brand' => $station->brand->name,
                'report' => $this->reportPayload($report),
            ]);
        }

        return [
            'matched' => true,
            'reason' => self::REASON_MATCHED,
            'doe_region' => $doeRegion,
            'doe_area' => $branded->first()->area,
            'brand' => $station->brand->name,
            'prices' => $branded
                ->sortBy('product')
                ->map(fn (FuelPrice $row): array => [
                    'product' => $row->product,
                    'fuel_code' => $row->fuel_code,
                    'min_price' => $row->min_price !== null ? (float) $row->min_price : null,
                    'max_price' => $row->max_price !== null ? (float) $row->max_price : null,
                ])
                ->values()
                ->all(),
            'report' => $this->reportPayload($report),
            'attribution' => $this->attribution(),
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function regional(string $reason, array $context = []): array
    {
        return array_merge([
            'matched' => false,
            'reason' => $reason,
            // The label the UI must use. Never "price", never "live".
            'label' => 'DOE Regional Reference',
            'prices' => [],
            'attribution' => $this->attribution(),
        ], $context);
    }

    /** @return array<string, string> */
    private function attribution(): array
    {
        return [
            'source' => 'Philippine Department of Energy',
            // Said plainly, because the distinction is the whole point: these
            // are weekly monitoring figures for an area, not a reading taken
            // at this forecourt.
            'basis' => 'Weekly area price monitoring. Not a live station price.',
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function reportPayload(?FuelReport $report): ?array
    {
        if ($report === null) {
            return null;
        }

        return [
            'id' => $report->id,
            'coverage_start' => $report->coverage_start->toDateString(),
            'coverage_end' => $report->coverage_end->toDateString(),
            'coverage_label' => $report->coverage_label,
            'monitoring_date' => $report->monitoring_date?->toDateString(),
            'source_url' => $report->source_url,
        ];
    }

    /** @var array<string, FuelReport|null> */
    private array $reportCache = [];

    private function latestReportFor(string $doeRegion): ?FuelReport
    {
        // Cached per instance: a station list resolves the same handful of
        // regions once per row otherwise, and the resource is rendered for
        // every station on the page.
        return $this->reportCache[$doeRegion] ??= FuelReport::query()
            ->where('region', $doeRegion)
            ->orderByDesc('coverage_start')
            ->first();
    }

    /** @var array<int, Collection<int, FuelPrice>> */
    private array $priceCache = [];

    /** @return Collection<int, FuelPrice> */
    private function pricesFor(FuelReport $report): Collection
    {
        return $this->priceCache[$report->id] ??= FuelPrice::query()
            ->where('report_id', $report->id)
            ->get();
    }
}
