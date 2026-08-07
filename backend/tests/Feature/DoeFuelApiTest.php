<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsDoeDatabase;
use Tests\TestCase;

/**
 * The public /fuel endpoints.
 *
 * These read the scraper's database, which the platform does not migrate — see
 * BuildsDoeDatabase for why the schema is built by hand here.
 */
class DoeFuelApiTest extends TestCase
{
    use BuildsDoeDatabase;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDoeDatabase();
        $this->seedDoeData();
    }

    private function seedDoeData(): void
    {
        $petron = $this->doeStation([
            'company' => 'Petron',
            'name' => 'Petron EDSA',
            'city' => 'Quezon City',
            'province' => 'Metro Manila',
        ]);

        $shell = $this->doeStation([
            'company' => 'Shell',
            'name' => 'Shell Ortigas',
            'city' => 'Pasig',
            'province' => 'Metro Manila',
            'barangay' => 'San Antonio',
        ]);

        $seaoil = $this->doeStation([
            'company' => 'SEAOIL',
            'name' => 'SEAOIL Bacolod',
            'city' => 'Bacolod',
            'province' => 'Negros Occidental',
            'barangay' => 'Mandalagan',
        ]);

        // Last week.
        $this->doePrice($petron, '2026-07-30', ['ron95' => 61.00, 'diesel' => 55.50]);
        $this->doePrice($shell, '2026-07-30', ['ron95' => 62.00, 'diesel' => 56.00]);
        $this->doePrice($seaoil, '2026-07-30', ['ron95' => 60.00, 'diesel' => 54.50]);

        // The most recent date.
        $this->doePrice($petron, '2026-08-06', ['ron95' => 62.40, 'diesel' => 56.85, 'ron97' => 66.10]);
        $this->doePrice($shell, '2026-08-06', ['ron95' => 63.10, 'diesel' => 57.40, 'ron91' => null]);
        $this->doePrice($seaoil, '2026-08-06', ['ron95' => 61.20, 'diesel' => 55.90]);
    }

    // -- latest --------------------------------------------------------------

    public function test_latest_returns_only_the_most_recent_date(): void
    {
        $response = $this->getJson('/api/v1/fuel/latest');

        $this->assertApiSuccess($response);

        $dates = collect($response->json('data'))->pluck('price_date')->unique();

        // Three stations on 2026-08-06, and nothing from the week before.
        $this->assertCount(3, $response->json('data'));
        $this->assertSame(['2026-08-06'], $dates->values()->all());
    }

    public function test_latest_carries_the_national_statistics(): void
    {
        // The summary travels with the page so a client does not have to make a
        // second call that could straddle a scraper run.
        $response = $this->getJson('/api/v1/fuel/latest?fuel=ron95');

        $statistics = $response->json('meta.statistics');

        $this->assertSame(61.20, $statistics['lowest']);
        $this->assertSame(63.10, $statistics['highest']);
        $this->assertEqualsWithDelta(62.23, $statistics['average'], 0.01);
        $this->assertSame(1.90, $statistics['spread']);
    }

    public function test_every_grade_is_present_even_when_unsold(): void
    {
        // A stable key set means a client can render a fixed table without
        // checking which grades exist at this station.
        $first = $this->getJson('/api/v1/fuel/latest')->json('data.0.fuels');

        $this->assertSame(
            ['ron91', 'ron95', 'ron97', 'ron100', 'diesel', 'diesel_plus'],
            array_keys($first),
        );
        $this->assertNull($first['ron100']['price']);
    }

    // -- search --------------------------------------------------------------

    public function test_search_filters_by_city(): void
    {
        $response = $this->getJson('/api/v1/fuel/search?city=Pasig');

        $this->assertApiSuccess($response);
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Shell Ortigas', $response->json('data.0.station.name'));
    }

    public function test_search_filters_by_province(): void
    {
        $response = $this->getJson('/api/v1/fuel/search?province=Negros');

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('SEAOIL', $response->json('data.0.station.company'));
    }

    public function test_search_filters_by_barangay(): void
    {
        $response = $this->getJson('/api/v1/fuel/search?barangay=Mandalagan');

        $this->assertCount(1, $response->json('data'));
    }

    public function test_search_filters_by_price_range(): void
    {
        $response = $this->getJson('/api/v1/fuel/search?fuel=ron95&min_price=62&max_price=63');

        $this->assertCount(1, $response->json('data'));
        $this->assertSame(62.40, $response->json('data.0.fuels.ron95.price'));
    }

    public function test_search_sorts_by_the_chosen_grade(): void
    {
        $prices = collect($this->getJson('/api/v1/fuel/search?fuel=ron95&sort=asc')->json('data'))
            ->pluck('fuels.ron95.price');

        $this->assertSame([61.20, 62.40, 63.10], $prices->all());
    }

    public function test_search_defaults_to_the_latest_date(): void
    {
        // Without it the search would return every day ever recorded — for a
        // national feed, millions of rows differing only by date.
        $dates = collect($this->getJson('/api/v1/fuel/search')->json('data'))
            ->pluck('price_date')
            ->unique();

        $this->assertSame(['2026-08-06'], $dates->values()->all());
    }

    public function test_a_specific_date_can_be_requested(): void
    {
        $response = $this->getJson('/api/v1/fuel/search?date=2026-07-30');

        $this->assertCount(3, $response->json('data'));
        $this->assertSame('2026-07-30', $response->json('data.0.price_date'));
    }

    public function test_an_unknown_fuel_type_is_rejected(): void
    {
        // A typo in ?fuel= must be an error, not a response quietly computed
        // over a different column.
        $response = $this->getJson('/api/v1/fuel/search?fuel=ron93');

        $response->assertStatus(422)->assertJsonPath('error.code', 'invalid_fuel_type');
    }

    public function test_a_sql_injection_attempt_in_the_fuel_parameter_is_rejected(): void
    {
        // The fuel name is interpolated into SQL because it is a column, not a
        // value. The allow-list is what makes that safe, so it is asserted.
        $response = $this->getJson('/api/v1/fuel/search?fuel='.urlencode('ron95`, (SELECT 1)) -- '));

        $response->assertStatus(422);
    }

    // -- stations ------------------------------------------------------------

    public function test_stations_lists_the_directory(): void
    {
        $response = $this->getJson('/api/v1/fuel/stations');

        $this->assertApiSuccess($response);
        $this->assertCount(3, $response->json('data'));
    }

    public function test_stations_can_be_searched_by_free_text(): void
    {
        $response = $this->getJson('/api/v1/fuel/stations?q=Ortigas');

        $this->assertCount(1, $response->json('data'));
    }

    public function test_a_station_without_a_published_address_still_reads_sensibly(): void
    {
        $this->doeStation([
            'company' => 'Unioil',
            'name' => 'Unioil Cainta',
            'city' => 'Cainta',
            'barangay' => 'Santo Domingo',
            'address' => null,
        ]);

        $response = $this->getJson('/api/v1/fuel/stations?q=Cainta');

        // Assembled from the geography rather than left blank, which reads to
        // a user as a data error.
        $this->assertSame(
            'Santo Domingo, Cainta, Metro Manila',
            $response->json('data.0.address'),
        );
    }

    // -- company and city ----------------------------------------------------

    public function test_company_returns_that_companys_prices(): void
    {
        $response = $this->getJson('/api/v1/fuel/company/Shell');

        $this->assertApiSuccess($response);
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Shell', $response->json('meta.company'));
    }

    public function test_city_reports_the_cheapest_and_most_expensive(): void
    {
        $response = $this->getJson('/api/v1/fuel/city/Quezon City?fuel=ron95');

        $this->assertApiSuccess($response);
        $this->assertSame('Petron EDSA', $response->json('meta.cheapest.station.name'));
    }

    public function test_an_unknown_city_returns_an_empty_page_rather_than_an_error(): void
    {
        $response = $this->getJson('/api/v1/fuel/city/Atlantis');

        $this->assertApiSuccess($response);
        $this->assertSame([], $response->json('data'));
    }

    // -- compare -------------------------------------------------------------

    public function test_compare_puts_stations_side_by_side(): void
    {
        $ids = collect($this->getJson('/api/v1/fuel/stations')->json('data'))->pluck('id');

        $response = $this->getJson('/api/v1/fuel/compare?stations='.$ids->take(2)->implode(','));

        $this->assertApiSuccess($response);
        $this->assertCount(2, $response->json('data'));
    }

    public function test_compare_marks_the_cheapest_grade(): void
    {
        $ids = collect($this->getJson('/api/v1/fuel/stations')->json('data'))->pluck('id');

        $response = $this->getJson('/api/v1/fuel/compare?stations='.$ids->implode(','));

        $winners = collect($response->json('data'))
            ->filter(fn (array $row): bool => $row['fuels']['ron95']['is_cheapest'])
            ->pluck('station.name');

        // SEAOIL at 61.20 is the cheapest of the three, and exactly one row is
        // marked — a client should not have to compute this itself.
        $this->assertSame(['SEAOIL Bacolod'], $winners->values()->all());
    }

    public function test_compare_needs_at_least_two_stations(): void
    {
        $this->getJson('/api/v1/fuel/compare?stations=1')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'invalid_comparison');
    }

    public function test_compare_reports_ids_that_returned_nothing(): void
    {
        $ids = collect($this->getJson('/api/v1/fuel/stations')->json('data'))->pluck('id');

        $response = $this->getJson('/api/v1/fuel/compare?stations='.$ids->first().',999999');

        // Otherwise a missing station is indistinguishable from one that does
        // not exist, and the client cannot say which.
        $this->assertSame([999999], $response->json('meta.missing'));
    }

    // -- history and insights -------------------------------------------------

    public function test_history_returns_the_series_for_one_station(): void
    {
        $stationId = $this->getJson('/api/v1/fuel/stations?q=Petron')->json('data.0.id');

        $response = $this->getJson("/api/v1/fuel/history?station_id={$stationId}");

        $this->assertApiSuccess($response);
        $this->assertCount(2, $response->json('data'));
    }

    public function test_insights_ranks_provinces_cheapest_first(): void
    {
        $response = $this->getJson('/api/v1/fuel/insights?fuel=ron95');

        $this->assertApiSuccess($response);

        $ranking = collect($response->json('data.province_ranking'));

        // Negros Occidental averages 61.20 against Metro Manila's 62.75.
        $this->assertSame('Negros Occidental', $ranking->first()['name']);
    }

    public function test_insights_reports_the_weekly_change_with_a_direction(): void
    {
        $weekly = $this->getJson('/api/v1/fuel/insights?fuel=ron95')->json('data.changes.ron95.weekly');

        // 61.00 average on 07-30 against 62.23 on 08-06.
        $this->assertSame('increase', $weekly['direction']);
        $this->assertGreaterThan(0, $weekly['change']);
    }

    public function test_a_price_cut_is_reported_as_a_rollback(): void
    {
        // The DOE's own word, and the one the rest of the platform uses.
        $station = $this->doeStation(['company' => 'Jetti', 'name' => 'Jetti Taguig', 'city' => 'Taguig']);
        $this->doePrice($station, '2026-08-06', ['ron100' => 70.00, 'ron95' => null, 'diesel' => null, 'ron91' => null]);
        $this->doePrice($station, '2026-07-30', ['ron100' => 75.00, 'ron95' => null, 'diesel' => null, 'ron91' => null]);

        $weekly = $this->getJson('/api/v1/fuel/insights?fuel=ron100')->json('data.changes.ron100.weekly');

        $this->assertSame('rollback', $weekly['direction']);
    }

    public function test_no_comparable_history_is_unknown_rather_than_no_change(): void
    {
        // A client that shows a flat arrow for "nothing to compare" is lying
        // about it.
        $fresh = $this->getJson('/api/v1/fuel/insights?fuel=diesel_plus')
            ->json('data.changes.diesel_plus.weekly');

        $this->assertSame('unknown', $fresh['direction']);
        $this->assertNull($fresh['change']);
    }

    // -- limits --------------------------------------------------------------

    public function test_per_page_is_capped(): void
    {
        // These endpoints are public and unauthenticated; an uncapped per_page
        // over a national table is a free denial of service.
        $response = $this->getJson('/api/v1/fuel/latest?per_page=100000');

        $this->assertLessThanOrEqual(100, $response->json('meta.per_page'));
    }

    public function test_the_endpoints_are_public(): void
    {
        // No token was set in any test above; asserting it directly so a later
        // middleware change does not quietly close them.
        $this->getJson('/api/v1/fuel/latest')->assertOk();
        $this->getJson('/api/v1/fuel/stations')->assertOk();
        $this->getJson('/api/v1/fuel/insights')->assertOk();
    }
}
