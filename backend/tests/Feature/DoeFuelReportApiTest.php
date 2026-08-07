<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Doe\Models\FuelPrice;
use App\Domain\Doe\Models\FuelReport;
use App\Domain\Doe\Models\ImportRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The public /fuel endpoints over the DOE's published reports.
 *
 * The data here is shaped exactly as the source publishes it: a min-max range
 * per brand per area, and a separate overall row carrying the range and the
 * common price the department states outright.
 */
class DoeFuelReportApiTest extends TestCase
{
    use RefreshDatabase;

    private function report(string $region, string $start, string $end, array $overrides = []): FuelReport
    {
        return FuelReport::create(array_merge([
            'region' => $region,
            'coverage_start' => $start,
            'coverage_end' => $end,
            'publication_date' => $start,
            'monitoring_date' => $start,
            'pdf_filename' => str($region)->slug().'-'.$start.'.pdf',
            'source_url' => 'https://prod-cms.doe.gov.ph/documents/d/guest/'.str($region)->slug(),
            'checksum' => hash('sha256', $region.$start),
            'extractor' => 'pdfplumber-coordinates',
            'quality' => 0.97,
            'areas_count' => 1,
            'rows_count' => 3,
        ], $overrides));
    }

    private function price(FuelReport $report, array $overrides = []): FuelPrice
    {
        return FuelPrice::create(array_merge([
            'report_id' => $report->id,
            'area' => 'Quezon City',
            'product' => 'RON 95',
            'fuel_code' => 'gasoline_ron95',
            'brand' => 'Petron',
            'min_price' => 79.50,
            'max_price' => 87.50,
            'created_at' => now(),
        ], $overrides));
    }

    private function seedTwoWeeks(): void
    {
        $lastWeek = $this->report('NCR', '2026-07-21', '2026-07-27');
        $this->price($lastWeek, ['min_price' => 78.00, 'max_price' => 86.00]);
        $this->price($lastWeek, [
            'brand' => null, 'min_price' => 71.00, 'max_price' => 94.00, 'common_price' => 85.00,
        ]);

        $thisWeek = $this->report('NCR', '2026-07-28', '2026-08-03');
        $this->price($thisWeek);
        $this->price($thisWeek, ['brand' => 'Shell', 'min_price' => 87.60, 'max_price' => 93.00]);
        $this->price($thisWeek, [
            'brand' => null, 'min_price' => 71.80, 'max_price' => 93.90, 'common_price' => 87.10,
        ]);
        $this->price($thisWeek, [
            'product' => 'DIESEL', 'fuel_code' => 'diesel', 'brand' => 'Shell',
            'min_price' => 92.80, 'max_price' => 95.70,
        ]);
    }

    // -- latest --------------------------------------------------------------

    public function test_latest_returns_only_the_most_recent_report_per_region(): void
    {
        $this->seedTwoWeeks();

        $response = $this->getJson('/api/v1/fuel/latest');

        $this->assertApiSuccess($response);
        // Four rows from the 28 July report, none from the week before.
        $this->assertCount(4, $response->json('data'));
    }

    public function test_latest_is_anchored_to_the_data_not_the_calendar(): void
    {
        // A region whose report did not appear this week should show the last
        // one published. An empty response reads as an outage.
        $old = $this->report('Region V (Bicol)', '2025-01-06', '2025-01-12');
        $this->price($old);

        $response = $this->getJson('/api/v1/fuel/latest?region=Bicol');

        $this->assertCount(1, $response->json('data'));
    }

    public function test_latest_carries_the_reports_the_figures_came_from(): void
    {
        // A price without its publication is a number with no provenance.
        $this->seedTwoWeeks();

        $response = $this->getJson('/api/v1/fuel/latest');

        $this->assertSame('NCR', $response->json('meta.reports.0.region'));
        $this->assertSame('2026-07-28', $response->json('meta.reports.0.coverage_start'));
        $this->assertNotNull($response->json('meta.reports.0.source_url'));
    }

    public function test_a_brand_row_and_an_overall_row_are_distinguishable(): void
    {
        $this->seedTwoWeeks();

        $rows = collect($this->getJson('/api/v1/fuel/latest')->json('data'));

        $overall = $rows->firstWhere('is_overall', true);
        $branded = $rows->firstWhere('brand', 'Petron');

        $this->assertNull($overall['brand']);
        $this->assertSame(87.10, $overall['common_price']);
        $this->assertFalse($branded['is_overall']);
        // Only the DOE's own summary row carries a common price.
        $this->assertNull($branded['common_price']);
    }

    public function test_prices_are_published_as_a_range_not_a_single_figure(): void
    {
        // Collapsing the range would invent a number the DOE never published.
        $this->seedTwoWeeks();

        $row = collect($this->getJson('/api/v1/fuel/latest')->json('data'))
            ->firstWhere('brand', 'Petron');

        $this->assertSame(79.50, $row['min_price']);
        $this->assertSame(87.50, $row['max_price']);
    }

    // -- filters and search ---------------------------------------------------

    public function test_it_filters_by_brand(): void
    {
        $this->seedTwoWeeks();

        $response = $this->getJson('/api/v1/fuel/latest?brand=Shell');

        $this->assertCount(2, $response->json('data'));
    }

    public function test_it_filters_by_fuel_code(): void
    {
        $this->seedTwoWeeks();

        $response = $this->getJson('/api/v1/fuel/latest?fuel_code=diesel');

        $this->assertCount(1, $response->json('data'));
    }

    public function test_search_by_price_range_matches_overlapping_ranges(): void
    {
        // A published range overlaps the query when its maximum is above the
        // floor and its minimum below the ceiling. Comparing a single end
        // would miss a brand whose band straddles the boundary.
        $this->seedTwoWeeks();

        $response = $this->getJson('/api/v1/fuel/search?min_price=88&max_price=90');

        $brands = collect($response->json('data'))->pluck('brand');

        $this->assertTrue($brands->contains('Shell'));
    }

    public function test_search_can_exclude_the_summary_rows(): void
    {
        $this->seedTwoWeeks();

        $response = $this->getJson('/api/v1/fuel/search?branded_only=1');

        $this->assertNotContains(null, collect($response->json('data'))->pluck('brand')->all());
    }

    public function test_search_defaults_to_the_latest_reports(): void
    {
        // Without a date bound this would return every week ever published.
        $this->seedTwoWeeks();

        $dates = collect($this->getJson('/api/v1/fuel/search')->json('data'))
            ->pluck('report.coverage_start')
            ->unique();

        $this->assertSame(['2026-07-28'], $dates->values()->all());
    }

    // -- history, areas, brands ----------------------------------------------

    public function test_history_spans_every_published_week(): void
    {
        $this->seedTwoWeeks();

        $response = $this->getJson('/api/v1/fuel/history?fuel_code=gasoline_ron95');

        $weeks = collect($response->json('data'))->pluck('report.coverage_start')->unique();

        $this->assertCount(2, $weeks);
    }

    public function test_history_can_be_bounded_by_date(): void
    {
        $this->seedTwoWeeks();

        $response = $this->getJson('/api/v1/fuel/history?date_from=2026-07-28');

        $weeks = collect($response->json('data'))->pluck('report.coverage_start')->unique();

        $this->assertSame(['2026-07-28'], $weeks->values()->all());
    }

    public function test_areas_lists_the_cities_the_doe_monitors(): void
    {
        $this->seedTwoWeeks();

        $response = $this->getJson('/api/v1/fuel/areas');

        $this->assertApiSuccess($response);
        $this->assertSame('Quezon City', $response->json('data.0.area'));
        $this->assertSame('NCR', $response->json('data.0.region'));
    }

    public function test_brands_excludes_the_summary_rows(): void
    {
        // The overall row carries no brand; counting it would produce a
        // nameless entry in the brand list.
        $this->seedTwoWeeks();

        $brands = collect($this->getJson('/api/v1/fuel/brands')->json('data'))->pluck('brand');

        $this->assertEqualsCanonicalizing(['Petron', 'Shell'], $brands->all());
    }

    // -- trends ---------------------------------------------------------------

    public function test_trends_returns_one_point_per_week_oldest_first(): void
    {
        $this->seedTwoWeeks();

        $response = $this->getJson('/api/v1/fuel/trends?fuel_code=gasoline_ron95');

        $this->assertApiSuccess($response);

        $points = $response->json('data');

        $this->assertCount(2, $points);
        // A chart reads left to right.
        $this->assertSame('2026-07-21', $points[0]['coverage_start']);
        $this->assertSame('2026-07-28', $points[1]['coverage_start']);
    }

    public function test_trends_bounds_each_point_with_the_published_range(): void
    {
        $this->seedTwoWeeks();

        $point = $this->getJson('/api/v1/fuel/trends?fuel_code=gasoline_ron95')->json('data.1');

        $this->assertSame(71.80, $point['lowest']);
        $this->assertSame(93.90, $point['highest']);
        $this->assertSame(87.10, $point['common']);
    }

    public function test_trends_says_that_its_points_are_midpoints(): void
    {
        // The caller must not read a midpoint as a quoted price.
        $this->seedTwoWeeks();

        $response = $this->getJson('/api/v1/fuel/trends?fuel_code=gasoline_ron95');

        $this->assertStringContainsString('midpoint', $response->json('meta.note'));
    }

    public function test_trends_needs_a_fuel_code(): void
    {
        // Averaging diesel with premium petrol is not a number that means
        // anything.
        $this->getJson('/api/v1/fuel/trends')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'fuel_code_required');
    }

    // -- admin dashboard ------------------------------------------------------

    public function test_imports_reports_ingestion_health(): void
    {
        $this->seedTwoWeeks();

        ImportRun::create([
            'started_at' => Carbon::parse('2026-08-04 06:00:00'),
            'finished_at' => Carbon::parse('2026-08-04 06:02:30'),
            'duration_seconds' => 150.0,
            'pdfs_discovered' => 18,
            'reports_imported' => 2,
            'records_imported' => 540,
            'status' => ImportRun::STATUS_SUCCESS,
        ]);

        $response = $this->getJson('/api/v1/fuel/imports');

        $this->assertApiSuccess($response);
        $this->assertSame(150.0, $response->json('data.last_import_duration_seconds'));
        $this->assertSame(540, $response->json('data.runs.0.records_imported'));
        $this->assertSame('2026-07-28', $response->json('data.latest_publication_date'));
        $this->assertNotNull($response->json('data.latest_reports.0.checksum'));
    }

    public function test_a_failed_run_is_surfaced(): void
    {
        ImportRun::create([
            'started_at' => now(),
            'finished_at' => now(),
            'status' => ImportRun::STATUS_FAILED,
            'errors' => 'Best extraction scored 0.31, below the threshold.',
        ]);

        $response = $this->getJson('/api/v1/fuel/imports');

        $this->assertCount(1, $response->json('data.failed_runs'));
        $this->assertNull($response->json('data.last_successful_run'));
    }

    public function test_a_quiet_week_counts_as_healthy(): void
    {
        // The DOE publishing nothing is a successful run. Treating it as a
        // failure would train an operator to ignore the dashboard.
        ImportRun::create([
            'started_at' => now(),
            'finished_at' => now(),
            'status' => ImportRun::STATUS_NO_CHANGES,
        ]);

        $response = $this->getJson('/api/v1/fuel/imports');

        $this->assertNotNull($response->json('data.last_successful_run'));
        $this->assertSame([], $response->json('data.failed_runs'));
    }

    // -- limits ---------------------------------------------------------------

    public function test_per_page_is_capped(): void
    {
        $this->seedTwoWeeks();

        $response = $this->getJson('/api/v1/fuel/latest?per_page=100000');

        $this->assertLessThanOrEqual(200, $response->json('meta.per_page'));
    }

    public function test_the_endpoints_are_public(): void
    {
        // No token was set in any test above; asserted directly so a later
        // middleware change cannot quietly close them.
        $this->getJson('/api/v1/fuel/latest')->assertOk();
        $this->getJson('/api/v1/fuel/areas')->assertOk();
        $this->getJson('/api/v1/fuel/brands')->assertOk();
    }
}
