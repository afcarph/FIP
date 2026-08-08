<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Doe\Models\FuelPrice;
use App\Domain\Doe\Models\FuelReport;
use App\Domain\Doe\Services\DoeAreaNormaliser;
use App\Domain\Doe\Services\DoeStationReference;
use App\Domain\Station\Models\GasStation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * When a DOE price may be shown against a station, and when it may not.
 *
 * The DOE publishes no station-level data at all. Every association here is
 * derived, so the tests that matter most are the ones asserting a *refusal* —
 * a rule that only ever says yes would be indistinguishable from no rule.
 */
class DoeStationReferenceTest extends TestCase
{
    use RefreshDatabase;

    private function reference(): DoeStationReference
    {
        return new DoeStationReference(new DoeAreaNormaliser);
    }

    private function region(string $code, string $name): int
    {
        return (int) DB::table('regions')->insertGetId([
            'code' => $code, 'name' => $name, 'island_group' => 'luzon',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function city(string $name, string $province, int $regionId): int
    {
        $provinceId = (int) DB::table('provinces')->insertGetId([
            'name' => $province,
            'code' => 'p'.substr(md5($province.$regionId), 0, 10),
            'region_id' => $regionId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return (int) DB::table('cities')->insertGetId([
            'name' => $name,
            'code' => 'c'.substr(md5($name.$provinceId), 0, 10),
            'province_id' => $provinceId,
            'is_city' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function brand(string $code, string $name): int
    {
        return (int) DB::table('brands')->insertGetId([
            'code' => $code, 'name' => $name, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function station(int $brandId, int $cityId, bool $verified = true): GasStation
    {
        $id = DB::table('gas_stations')->insertGetId([
            'brand_id' => $brandId,
            'name' => 'Test Station',
            'slug' => 'test-station-'.uniqid(),
            'address_line' => '1 Test Road',
            'city_id' => $cityId,
            'latitude' => 14.5547,
            'longitude' => 121.0244,
            'is_24_hours' => true,
            'has_ev_charging' => false,
            'status' => 'active',
            'verified_at' => $verified ? now() : null,
            'rating_avg' => 0,
            'rating_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return GasStation::query()->with(['brand', 'city.province.region'])->findOrFail($id);
    }

    private function report(string $region, string $start, string $end): FuelReport
    {
        return FuelReport::create([
            'region' => $region,
            'coverage_start' => $start,
            'coverage_end' => $end,
            'publication_date' => $start,
            'monitoring_date' => $start,
            'pdf_filename' => 'r-'.uniqid().'.pdf',
            'checksum' => hash('sha256', $region.$start.uniqid()),
            'extractor' => 'pdfplumber-coordinates',
            'quality' => 1.0,
            'areas_count' => 1,
            'rows_count' => 1,
        ]);
    }

    private function price(FuelReport $report, string $area, ?string $brand, ?string $province = null): FuelPrice
    {
        return FuelPrice::create([
            'report_id' => $report->id,
            'area' => $area,
            'province' => $province,
            'product' => 'RON 95',
            'fuel_code' => 'gasoline_ron95',
            'brand' => $brand,
            'min_price' => 61.50,
            'max_price' => 63.20,
        ]);
    }

    // -- 1. the happy path ----------------------------------------------------

    public function test_a_verified_ncr_station_matches_its_area_and_brand(): void
    {
        $ncr = $this->region('NCR', 'National Capital Region');
        $report = $this->report('NCR', '2026-07-28', '2026-08-03');
        $this->price($report, 'Makati City', 'Petron');

        $station = $this->station($this->brand('petron', 'Petron'), $this->city('Makati', 'Metro Manila', $ncr));

        $result = $this->reference()->for($station);

        $this->assertTrue($result['matched']);
        $this->assertSame('NCR', $result['doe_region']);
        $this->assertSame('Makati City', $result['doe_area']);
        $this->assertCount(1, $result['prices']);
        $this->assertSame('2026-07-28', $result['report']['coverage_start']);
    }

    // -- 2. area normalisation ------------------------------------------------

    public function test_area_normalisation_is_symmetric(): void
    {
        // "Quezon City" on both sides, and "Taguig" against the DOE's own
        // "Taguig Cty" typo. Normalising only the DOE side reported every
        // Quezon City station unmatched, because the platform's name already
        // ends in "City" and the DOE's had just had it stripped.
        $normaliser = new DoeAreaNormaliser;

        $this->assertTrue($normaliser->matches('Quezon City', 'Quezon City'));
        $this->assertTrue($normaliser->matches('Makati City', 'Makati'));
        $this->assertTrue($normaliser->matches('Taguig Cty', 'Taguig'));
        $this->assertTrue($normaliser->matches('Parañaque City', 'Paranaque'));
        $this->assertFalse($normaliser->matches('Makati City', 'Manila'));
        // Two unknowns are not the same place.
        $this->assertFalse($normaliser->matches(null, null));
    }

    public function test_normalisation_is_not_fuzzy(): void
    {
        $normaliser = new DoeAreaNormaliser;

        // Substring and near-miss must both fail; a rule loose enough to
        // accept these would accept far worse at national scale.
        $this->assertFalse($normaliser->matches('San Juan', 'San Juan City Heights'));
        $this->assertFalse($normaliser->matches('Cebu', 'Cebu City North'));
        $this->assertFalse($normaliser->matches('Makati', 'Makat'));
    }

    // -- 3. brand matching ----------------------------------------------------

    public function test_brand_matches_on_platform_code_not_display_name(): void
    {
        // "Phoenix Petroleum" against the DOE's "Phoenix". Matching on the
        // display name drops this station silently.
        $ncr = $this->region('NCR', 'National Capital Region');
        $report = $this->report('NCR', '2026-07-28', '2026-08-03');
        $this->price($report, 'Quezon City', 'Phoenix');

        $station = $this->station(
            $this->brand('phoenix', 'Phoenix Petroleum'),
            $this->city('Quezon City', 'Metro Manila', $ncr),
        );

        $this->assertTrue($this->reference()->for($station)['matched']);
    }

    public function test_a_brand_with_no_doe_equivalent_does_not_match(): void
    {
        // "Independent" is the DOE's unbranded column, not a brand.
        $ncr = $this->region('NCR', 'National Capital Region');
        $report = $this->report('NCR', '2026-07-28', '2026-08-03');
        $this->price($report, 'Makati City', 'Independent');

        $station = $this->station($this->brand('petron', 'Petron'), $this->city('Makati', 'Metro Manila', $ncr));

        $result = $this->reference()->for($station);

        $this->assertFalse($result['matched']);
        $this->assertSame(DoeStationReference::REASON_BRAND_NOT_PUBLISHED, $result['reason']);
    }

    // -- 4 & 5. province ------------------------------------------------------

    public function test_a_matching_province_is_accepted(): void
    {
        $ncr = $this->region('NCR', 'National Capital Region');
        $report = $this->report('NCR', '2026-07-28', '2026-08-03');
        $this->price($report, 'Makati City', 'Petron', 'Metro Manila');

        $station = $this->station($this->brand('petron', 'Petron'), $this->city('Makati', 'Metro Manila', $ncr));

        $this->assertTrue($this->reference()->for($station)['matched']);
    }

    public function test_a_province_mismatch_is_refused(): void
    {
        $ncr = $this->region('NCR', 'National Capital Region');
        $report = $this->report('NCR', '2026-07-28', '2026-08-03');
        $this->price($report, 'Makati City', 'Petron', 'Cebu');

        $station = $this->station($this->brand('petron', 'Petron'), $this->city('Makati', 'Metro Manila', $ncr));

        $result = $this->reference()->for($station);

        $this->assertFalse($result['matched']);
        $this->assertSame(DoeStationReference::REASON_PROVINCE_MISMATCH, $result['reason']);
    }

    // -- 6. the collision this whole guard exists for -------------------------

    public function test_san_fernando_cebu_never_attaches_to_san_fernando_pampanga(): void
    {
        // Two real municipalities whose names match exactly. A join on name
        // alone puts Cebu's prices on a Pampanga forecourt — the exact
        // fabrication this integration is forbidden from producing.
        $r3 = $this->region('R3', 'Central Luzon');
        $report = $this->report('REGIONS 6-8', '2026-07-28', '2026-08-03');
        $this->price($report, 'San Fernando', 'Shell', 'Cebu');

        $station = $this->station(
            $this->brand('shell', 'Shell'),
            $this->city('San Fernando', 'Pampanga', $r3),
        );

        $result = $this->reference()->for($station);

        $this->assertFalse($result['matched']);
        $this->assertSame([], $result['prices']);
        // It fails at the region gate, before area is ever compared — the
        // outer guard, with province as the second line of defence.
        $this->assertSame(DoeStationReference::REASON_NO_REPORT, $result['reason']);
    }

    // -- 7. region ambiguity --------------------------------------------------

    public function test_regions_6_8_never_resolves_to_a_single_platform_region(): void
    {
        // The label spans Western, Central and Eastern Visayas. A station in
        // R6 and one in R7 would both claim it and one would be wrong.
        $r6 = $this->region('R6', 'Western Visayas');
        $report = $this->report('REGIONS 6-8', '2026-07-28', '2026-08-03');
        $this->price($report, 'Iloilo City', 'Petron');

        $station = $this->station($this->brand('petron', 'Petron'), $this->city('Iloilo', 'Iloilo', $r6));

        $result = $this->reference()->for($station);

        $this->assertFalse($result['matched']);
        $this->assertSame(DoeStationReference::REASON_NO_REPORT, $result['reason']);
    }

    // -- 8, 9, 10. absent data ------------------------------------------------

    public function test_a_region_with_no_report_yields_a_regional_reference(): void
    {
        $ncr = $this->region('NCR', 'National Capital Region');

        $station = $this->station($this->brand('petron', 'Petron'), $this->city('Makati', 'Metro Manila', $ncr));

        $result = $this->reference()->for($station);

        $this->assertFalse($result['matched']);
        $this->assertSame(DoeStationReference::REASON_NO_REPORT, $result['reason']);
        $this->assertSame('DOE Regional Reference', $result['label']);
    }

    public function test_an_area_the_doe_did_not_monitor_yields_a_regional_reference(): void
    {
        // Mandaluyong is absent from the DOE's NCR list in the real data.
        $ncr = $this->region('NCR', 'National Capital Region');
        $report = $this->report('NCR', '2026-07-28', '2026-08-03');
        $this->price($report, 'Makati City', 'Caltex');

        $station = $this->station($this->brand('caltex', 'Caltex'), $this->city('Mandaluyong', 'Metro Manila', $ncr));

        $result = $this->reference()->for($station);

        $this->assertFalse($result['matched']);
        $this->assertSame(DoeStationReference::REASON_AREA_NOT_MONITORED, $result['reason']);
    }

    public function test_a_brand_absent_from_that_area_yields_a_regional_reference(): void
    {
        $ncr = $this->region('NCR', 'National Capital Region');
        $report = $this->report('NCR', '2026-07-28', '2026-08-03');
        $this->price($report, 'Makati City', 'Petron');

        $station = $this->station($this->brand('seaoil', 'SEAOIL'), $this->city('Makati', 'Metro Manila', $ncr));

        $result = $this->reference()->for($station);

        $this->assertFalse($result['matched']);
        $this->assertSame(DoeStationReference::REASON_BRAND_NOT_PUBLISHED, $result['reason']);
    }

    // -- 11. unverified station ----------------------------------------------

    public function test_an_unverified_station_never_carries_a_doe_price(): void
    {
        $ncr = $this->region('NCR', 'National Capital Region');
        $report = $this->report('NCR', '2026-07-28', '2026-08-03');
        $this->price($report, 'Makati City', 'Petron');

        $station = $this->station(
            $this->brand('petron', 'Petron'),
            $this->city('Makati', 'Metro Manila', $ncr),
            verified: false,
        );

        $result = $this->reference()->for($station);

        $this->assertFalse($result['matched']);
        $this->assertSame(DoeStationReference::REASON_UNVERIFIED_STATION, $result['reason']);
    }

    // -- 12 & 13. coverage dates ---------------------------------------------

    public function test_a_valid_coverage_window_is_reported(): void
    {
        $ncr = $this->region('NCR', 'National Capital Region');
        $report = $this->report('NCR', '2026-07-28', '2026-08-03');
        $this->price($report, 'Makati City', 'Petron');

        $station = $this->station($this->brand('petron', 'Petron'), $this->city('Makati', 'Metro Manila', $ncr));

        $result = $this->reference()->for($station);

        $this->assertSame('2026-07-28', $result['report']['coverage_start']);
        $this->assertSame('2026-08-03', $result['report']['coverage_end']);
        $this->assertNotEmpty($result['report']['coverage_label']);
    }

    public function test_a_report_whose_coverage_runs_backwards_is_refused(): void
    {
        // An undated — or impossibly dated — price is not one anyone can act
        // on, and showing it implies a week it does not describe.
        $ncr = $this->region('NCR', 'National Capital Region');
        $report = $this->report('NCR', '2026-08-03', '2026-07-28');
        $this->price($report, 'Makati City', 'Petron');

        $station = $this->station($this->brand('petron', 'Petron'), $this->city('Makati', 'Metro Manila', $ncr));

        $result = $this->reference()->for($station);

        $this->assertFalse($result['matched']);
        $this->assertSame(DoeStationReference::REASON_INVALID_COVERAGE, $result['reason']);
    }

    // -- 14. never presented as a live station price -------------------------

    public function test_the_payload_never_calls_this_a_live_station_price(): void
    {
        $ncr = $this->region('NCR', 'National Capital Region');
        $report = $this->report('NCR', '2026-07-28', '2026-08-03');
        $this->price($report, 'Makati City', 'Petron');

        $station = $this->station($this->brand('petron', 'Petron'), $this->city('Makati', 'Metro Manila', $ncr));

        $result = $this->reference()->for($station);
        $encoded = strtolower(json_encode($result, JSON_THROW_ON_ERROR));

        $this->assertStringNotContainsString('live price', $encoded);
        $this->assertStringNotContainsString('current station price', $encoded);
        $this->assertSame('Philippine Department of Energy', $result['attribution']['source']);
        $this->assertStringContainsString('Not a live station price', $result['attribution']['basis']);
    }

    // -- 15. location is independent -----------------------------------------

    public function test_station_location_is_unaffected_by_the_absence_of_a_doe_match(): void
    {
        // Coordinates come from gas_stations and nowhere else. A station with
        // no DOE reference must still be findable on a map.
        $r3 = $this->region('R3', 'Central Luzon');

        $station = $this->station($this->brand('shell', 'Shell'), $this->city('Malolos', 'Bulacan', $r3));

        $result = $this->reference()->for($station);

        $this->assertFalse($result['matched']);
        $this->assertEqualsWithDelta(14.5547, (float) $station->latitude, 0.0001);
        $this->assertEqualsWithDelta(121.0244, (float) $station->longitude, 0.0001);
    }
}
