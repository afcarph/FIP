<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Doe\Data\DoeRecord;
use App\Domain\Doe\Data\StationMatch;
use App\Domain\Doe\Models\DoeStationReview;
use App\Domain\Doe\Services\StationMatcher;
use App\Domain\Station\Models\Brand;
use App\Domain\Station\Models\City;
use App\Domain\Station\Models\GasStation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Matching a DOE listing to a station in the directory.
 *
 * The tests are shaped by one asymmetry, which is also what shapes the
 * matcher: an unmatched station is visible — it lands in a review queue with a
 * suggestion. A wrong match writes one station's price onto another, reads as
 * a perfectly plausible price, and nothing downstream can detect it. So
 * several of these assert that the matcher *declines* to match.
 */
class DoeStationMatcherTest extends TestCase
{
    use RefreshDatabase;

    private StationMatcher $matcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatform();
        $this->matcher = app(StationMatcher::class);
    }

    private function station(string $brand, string $city, string $name, ?string $address = null): GasStation
    {
        return GasStation::factory()->create(array_filter([
            'brand_id' => Brand::where('name', $brand)->firstOrFail()->getKey(),
            'city_id' => City::where('name', $city)->firstOrFail()->getKey(),
            'name' => $name,
            // address_line is NOT NULL, so an omitted address falls through to
            // the factory's. That is closer to production than a blank column
            // anyway: the directory always has some address, and the tests that
            // exercise fuzzy matching pass no address on the *DOE* side, which
            // is what makes the address strategy inapplicable.
            'address_line' => $address,
        ], static fn ($value): bool => $value !== null));
    }

    private function record(array $overrides = []): DoeRecord
    {
        // array_merge, not `??` per field: `['company' => null]` means "this
        // listing has no company", and `??` would read it as "not overridden"
        // and hand back the default.
        $values = array_merge([
            'company' => 'Petron',
            'station' => 'Petron EDSA',
            'address' => null,
            'city' => 'Quezon City',
            'province' => 'Metro Manila',
            'barangay' => null,
            'latitude' => null,
            'longitude' => null,
            'prices' => ['diesel' => 56.85],
        ], $overrides);

        return new DoeRecord(
            company: $values['company'],
            station: $values['station'],
            address: $values['address'],
            city: $values['city'],
            province: $values['province'],
            barangay: $values['barangay'],
            latitude: $values['latitude'],
            longitude: $values['longitude'],
            priceDate: Carbon::parse('2026-08-06'),
            prices: $values['prices'],
        );
    }

    // -- priority ------------------------------------------------------------

    public function test_a_manual_mapping_wins_over_everything(): void
    {
        // A human already answered this question. A heuristic that happens to
        // score higher must not re-litigate it.
        $wrong = $this->station('Petron', 'Quezon City', 'Petron EDSA');
        $right = $this->station('Petron', 'Quezon City', 'Something Else Entirely');

        $record = $this->record();

        DoeStationReview::create([
            'fingerprint' => $record->fingerprint(),
            'doe_company' => 'Petron',
            'doe_station' => 'Petron EDSA',
            'doe_city' => 'Quezon City',
            'status' => DoeStationReview::STATUS_MAPPED,
            'resolved_station_id' => $right->getKey(),
        ]);

        $match = $this->matcher->match($record);

        $this->assertTrue($match->matched());
        $this->assertSame($right->getKey(), $match->station->getKey());
        $this->assertNotSame($wrong->getKey(), $match->station->getKey());
        $this->assertSame(StationMatch::STRATEGY_MANUAL, $match->strategy);
        $this->assertSame(1.0, $match->confidence);
    }

    public function test_an_exact_slug_resolves_by_identifier(): void
    {
        $station = $this->station('Petron', 'Quezon City', 'Petron EDSA');

        $match = $this->matcher->match($this->record(['station' => $station->slug]));

        $this->assertSame($station->getKey(), $match->station->getKey());
        $this->assertSame(StationMatch::STRATEGY_IDENTIFIER, $match->strategy);
    }

    public function test_a_matching_address_scores_highly(): void
    {
        $station = $this->station('Petron', 'Quezon City', 'EDSA Kamuning', '123 EDSA corner Kamuning Road');

        $match = $this->matcher->match($this->record([
            'station' => 'Petron EDSA Kamuning',
            'address' => '123 EDSA corner Kamuning Road',
        ]));

        $this->assertTrue($match->matched());
        $this->assertSame($station->getKey(), $match->station->getKey());
        $this->assertGreaterThanOrEqual(0.85, $match->confidence);
    }

    public function test_it_falls_back_to_fuzzy_when_only_the_name_is_close(): void
    {
        $station = $this->station('Petron', 'Quezon City', 'EDSA Kamuning');

        $match = $this->matcher->match($this->record(['station' => 'Petron EDSA Kamuning']));

        $this->assertTrue($match->matched());
        $this->assertSame($station->getKey(), $match->station->getKey());
        $this->assertSame(StationMatch::STRATEGY_FUZZY, $match->strategy);
    }

    public function test_a_fuzzy_match_never_claims_full_confidence(): void
    {
        // The confidence travels into PriceService, which uses it to decide
        // precedence. A fuzzy match reporting 1.0 could overwrite an
        // operator's own price.
        $this->station('Petron', 'Quezon City', 'EDSA Kamuning');

        $match = $this->matcher->match($this->record(['station' => 'Petron EDSA Kamuning']));

        $this->assertLessThan(1.0, $match->confidence);
    }

    // -- declining to match --------------------------------------------------

    public function test_the_brand_prefix_does_not_prevent_a_match(): void
    {
        // "Petron Shaw Boulevard" and "Shaw Boulevard" are the same site
        // written two ways.
        $station = $this->station('Petron', 'Quezon City', 'Shaw Boulevard');

        $match = $this->matcher->match($this->record(['station' => 'Petron Shaw Boulevard']));

        $this->assertTrue($match->matched());
        $this->assertSame($station->getKey(), $match->station->getKey());
    }

    public function test_a_different_city_is_disqualifying(): void
    {
        // Two stations of one brand in different cities are different sites,
        // however alike their names.
        $this->station('Petron', 'Makati', 'Petron EDSA');

        $match = $this->matcher->match($this->record(['city' => 'Quezon City']));

        $this->assertFalse($match->matched());
    }

    public function test_a_different_brand_is_not_considered(): void
    {
        $this->station('Shell', 'Quezon City', 'Petron EDSA');

        $match = $this->matcher->match($this->record(['company' => 'Petron']));

        $this->assertFalse($match->matched());
    }

    public function test_an_unrelated_name_falls_below_the_threshold(): void
    {
        $this->station('Petron', 'Quezon City', 'Novaliches Bayan Terminal');

        $match = $this->matcher->match($this->record(['station' => 'Petron EDSA Kamuning']));

        $this->assertFalse($match->matched());
        // The candidate is still carried, so review is a confirmation rather
        // than a search.
        $this->assertNotNull($match->bestCandidate());
    }

    public function test_a_listing_with_no_company_cannot_be_matched(): void
    {
        $this->station('Petron', 'Quezon City', 'Petron EDSA');

        $match = $this->matcher->match($this->record(['company' => null]));

        $this->assertFalse($match->matched());
    }

    public function test_an_empty_directory_is_a_miss_not_an_error(): void
    {
        $this->assertFalse($this->matcher->match($this->record())->matched());
    }

    public function test_the_best_candidate_wins_among_several(): void
    {
        $this->station('Petron', 'Quezon City', 'Novaliches');
        $best = $this->station('Petron', 'Quezon City', 'EDSA Kamuning');
        $this->station('Petron', 'Quezon City', 'Commonwealth');

        $match = $this->matcher->match($this->record(['station' => 'Petron EDSA Kamuning']));

        $this->assertSame($best->getKey(), $match->station->getKey());
    }

    public function test_flush_picks_up_a_mapping_made_since_the_last_batch(): void
    {
        $station = $this->station('Petron', 'Quezon City', 'Nothing Alike');
        $record = $this->record();

        $this->assertFalse($this->matcher->match($record)->matched());

        DoeStationReview::create([
            'fingerprint' => $record->fingerprint(),
            'status' => DoeStationReview::STATUS_MAPPED,
            'resolved_station_id' => $station->getKey(),
        ]);

        // Without the flush the run would keep using the mappings it cached at
        // the start, and a reviewer's work would not take effect until deploy.
        $this->matcher->flush();

        $this->assertTrue($this->matcher->match($record)->matched());
    }

    public function test_an_ignored_review_does_not_become_a_mapping(): void
    {
        // Depots and closed sites are marked ignored precisely so they stop
        // appearing; they must not start matching instead.
        $station = $this->station('Petron', 'Quezon City', 'Nothing Alike');
        $record = $this->record();

        DoeStationReview::create([
            'fingerprint' => $record->fingerprint(),
            'status' => DoeStationReview::STATUS_IGNORED,
            'resolved_station_id' => $station->getKey(),
        ]);

        $this->matcher->flush();

        $this->assertFalse($this->matcher->match($record)->matched());
    }
}
