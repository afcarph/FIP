<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Doe\Models\DoeImportBatch;
use App\Domain\Doe\Models\DoeStationReview;
use App\Domain\Doe\Services\DoeImportService;
use App\Domain\Doe\Services\ImportOutcome;
use App\Domain\Pricing\Models\FuelPriceHistory;
use App\Domain\Pricing\Models\FuelType;
use App\Domain\Pricing\Models\StationPrice;
use App\Domain\Station\Models\Brand;
use App\Domain\Station\Models\City;
use App\Domain\Station\Models\GasStation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsDoePayloads;
use Tests\TestCase;

/**
 * The whole pipeline: stored payload → parse → match → PriceService.
 *
 * What these assert is that the importer *uses* the platform rather than
 * reimplementing it. Prices land in `station_prices` and `fuel_price_history`
 * through PriceService, plausibility and precedence stay PriceService's, and
 * the scraper's own tables hold nothing but operational records.
 */
class DoeBatchImportTest extends TestCase
{
    use BuildsDoePayloads;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatform();
        // recordPrice dispatches alert evaluation; irrelevant here and it
        // needs a queue connection.
        Queue::fake();
    }

    private function station(string $brand, string $city, string $name): GasStation
    {
        return GasStation::factory()->create([
            'brand_id' => Brand::where('name', $brand)->firstOrFail()->getKey(),
            'city_id' => City::where('name', $city)->firstOrFail()->getKey(),
            'name' => $name,
        ]);
    }

    /**
     * @param list<array<int, mixed>> $rows
     */
    private function batch(string $priceDate, array $rows): DoeImportBatch
    {
        $payload = $this->doeEnvelope([$this->doeDataPayload($priceDate, $rows)]);

        return DoeImportBatch::create([
            'started_at' => now(),
            'status' => DoeImportBatch::STATUS_PENDING,
            'payload_hash' => hash('sha256', $payload),
            'raw_payload' => $payload,
        ]);
    }

    private function import(DoeImportBatch $batch): ImportOutcome
    {
        return app(DoeImportService::class)->import($batch);
    }

    private function fuelTypeId(string $code): int
    {
        return (int) FuelType::where('code', $code)->firstOrFail()->getKey();
    }

    // -- the happy path ------------------------------------------------------

    public function test_it_records_prices_through_the_platform_price_model(): void
    {
        $station = $this->station('Petron', 'Quezon City', 'EDSA Kamuning');

        $outcome = $this->import($this->batch('20260806', [
            ['Petron EDSA Kamuning', 'Petron', 'Quezon City', 'Metro Manila', '123 EDSA', 58.20, 62.40, 56.85],
        ]));

        $this->assertFalse($outcome->failed, $outcome->summary());
        $this->assertSame(3, $outcome->imported);

        $price = StationPrice::where('station_id', $station->getKey())
            ->where('fuel_type_id', $this->fuelTypeId('gasoline_ron95'))
            ->firstOrFail();

        $this->assertEqualsWithDelta(62.40, (float) $price->price, 0.001);
    }

    public function test_it_marks_the_source_as_doe(): void
    {
        // A value PriceService already knows: it stamps `verified_at` for it,
        // and the UI can say where a price came from.
        $this->station('Petron', 'Quezon City', 'EDSA Kamuning');

        $this->import($this->batch('20260806', [
            ['Petron EDSA Kamuning', 'Petron', 'Quezon City', 'NCR', 'a', null, 62.40, null],
        ]));

        $this->assertSame('doe', StationPrice::firstOrFail()->source);
    }

    public function test_history_is_preserved_by_the_service(): void
    {
        // The importer does not write fuel_price_history itself. This asserts
        // the service did, which is the difference between a working forecast
        // and a populated map with nothing behind it.
        $this->station('Petron', 'Quezon City', 'EDSA Kamuning');

        $this->import($this->batch('20260806', [
            ['Petron EDSA Kamuning', 'Petron', 'Quezon City', 'NCR', 'a', null, 62.40, null],
        ]));

        $this->assertSame(1, FuelPriceHistory::count());
    }

    public function test_a_later_day_appends_to_history_rather_than_replacing_it(): void
    {
        $this->station('Petron', 'Quezon City', 'EDSA Kamuning');

        $this->import($this->batch('20260806', [
            ['Petron EDSA Kamuning', 'Petron', 'Quezon City', 'NCR', 'a', null, 62.40, null],
        ]));
        $this->import($this->batch('20260807', [
            ['Petron EDSA Kamuning', 'Petron', 'Quezon City', 'NCR', 'a', null, 63.00, null],
        ]));

        $prices = FuelPriceHistory::orderBy('recorded_on')
            ->pluck('price')
            ->map(static fn ($p): float => round((float) $p, 2))
            ->all();

        $this->assertSame([62.40, 63.00], $prices);

        // station_prices keeps only the current one, with the previous beside it.
        $current = StationPrice::firstOrFail();
        $this->assertEqualsWithDelta(63.00, (float) $current->price, 0.001);
        $this->assertEqualsWithDelta(62.40, (float) $current->previous_price, 0.001);
    }

    // -- idempotency ---------------------------------------------------------

    public function test_importing_the_same_batch_twice_creates_no_duplicate(): void
    {
        // Idempotency is not implemented here — it falls out of PriceService
        // refusing a reading that does not supersede the stored one. The test
        // is on the outcome, not the mechanism.
        $this->station('Petron', 'Quezon City', 'EDSA Kamuning');

        $batch = $this->batch('20260806', [
            ['Petron EDSA Kamuning', 'Petron', 'Quezon City', 'NCR', 'a', null, 62.40, null],
        ]);

        $this->import($batch);
        $historyAfterFirst = FuelPriceHistory::count();

        $this->import($batch->fresh());

        $this->assertSame($historyAfterFirst, FuelPriceHistory::count());
        $this->assertSame(1, StationPrice::count());
    }

    public function test_replaying_a_batch_does_not_move_the_current_price(): void
    {
        $this->station('Petron', 'Quezon City', 'EDSA Kamuning');

        $first = $this->batch('20260806', [
            ['Petron EDSA Kamuning', 'Petron', 'Quezon City', 'NCR', 'a', null, 62.40, null],
        ]);
        $this->import($first);

        $second = $this->batch('20260807', [
            ['Petron EDSA Kamuning', 'Petron', 'Quezon City', 'NCR', 'a', null, 63.00, null],
        ]);
        $this->import($second);

        // Replaying the older batch must not walk the price backwards.
        $this->import($first->fresh());

        $this->assertEqualsWithDelta(63.00, (float) StationPrice::firstOrFail()->price, 0.001);
    }

    // -- replay --------------------------------------------------------------

    public function test_a_failed_batch_keeps_its_payload_for_replay(): void
    {
        // The reason the payload is stored at all: a parser fix should be
        // re-runnable against the batches it would have got wrong, and by then
        // the dashboard shows a different week.
        $batch = DoeImportBatch::create([
            'started_at' => now(),
            'status' => DoeImportBatch::STATUS_PENDING,
            'payload_hash' => 'x',
            'raw_payload' => $this->doeEnvelope([['dataResponse' => []]]),
        ]);

        $outcome = $this->import($batch);

        $this->assertTrue($outcome->failed);
        $this->assertSame(DoeImportBatch::STATUS_FAILED, $batch->fresh()->status);
        $this->assertTrue($batch->fresh()->isReplayable());
    }

    public function test_a_replayed_batch_imports_once_the_directory_has_the_station(): void
    {
        $batch = $this->batch('20260806', [
            ['Petron EDSA Kamuning', 'Petron', 'Quezon City', 'NCR', 'a', null, 62.40, null],
        ]);

        // Nothing to match yet — the listing goes to review.
        $this->import($batch);
        $this->assertSame(0, StationPrice::count());
        $this->assertSame(1, DoeStationReview::count());

        // The station is added, and the stored payload is replayed.
        $this->station('Petron', 'Quezon City', 'EDSA Kamuning');
        $outcome = $this->import($batch->fresh());

        $this->assertSame(1, $outcome->imported);
        $this->assertSame(1, StationPrice::count());
    }

    public function test_a_corrupt_payload_fails_the_batch_without_throwing(): void
    {
        // A command working through a queue of batches must not stop because
        // one of them is corrupt.
        $batch = DoeImportBatch::create([
            'started_at' => now(),
            'status' => DoeImportBatch::STATUS_PENDING,
            'payload_hash' => 'x',
            'raw_payload' => 'not json at all',
        ]);

        $outcome = $this->import($batch);

        $this->assertTrue($outcome->failed);
        $this->assertNotNull($batch->fresh()->error);
    }

    // -- unmatched stations --------------------------------------------------

    public function test_an_unmatched_listing_goes_to_review_with_its_details(): void
    {
        $this->import($this->batch('20260806', [
            ['Nowhere Fuels Atlantis', 'Nowhere Fuels', 'Atlantis', 'Deep', 'a', null, 62.40, null],
        ]));

        $review = DoeStationReview::firstOrFail();

        $this->assertSame('Nowhere Fuels', $review->doe_company);
        $this->assertSame('Atlantis', $review->doe_city);
        $this->assertSame(DoeStationReview::STATUS_PENDING, $review->status);
        $this->assertSame(0, StationPrice::count());
    }

    public function test_the_same_unmatched_listing_is_one_review_item(): void
    {
        // A queue that grows by thousands a week does not get worked.
        $rows = [['Nowhere Fuels Atlantis', 'Nowhere Fuels', 'Atlantis', 'Deep', 'a', null, 62.40, null]];

        $this->import($this->batch('20260806', $rows));
        $this->import($this->batch('20260807', $rows));
        $this->import($this->batch('20260808', $rows));

        $this->assertSame(1, DoeStationReview::count());
        $this->assertSame(3, DoeStationReview::firstOrFail()->times_seen);
    }

    public function test_one_unmatched_listing_does_not_cost_the_matched_ones(): void
    {
        $this->station('Petron', 'Quezon City', 'EDSA Kamuning');

        $outcome = $this->import($this->batch('20260806', [
            ['Petron EDSA Kamuning', 'Petron', 'Quezon City', 'NCR', 'a', null, 62.40, null],
            ['Nowhere Fuels Atlantis', 'Nowhere Fuels', 'Atlantis', 'Deep', 'b', null, 61.00, null],
        ]));

        $this->assertSame(1, $outcome->imported);
        $this->assertSame(1, $outcome->unmatched);
        $this->assertSame(1, StationPrice::count());
    }

    public function test_a_mapped_review_makes_the_next_import_land(): void
    {
        $station = $this->station('Shell', 'Pasig', 'Completely Different Name');

        $rows = [['Some DOE Listing', 'Shell', 'Pasig', 'NCR', 'a', null, 62.40, null]];

        $this->import($this->batch('20260806', $rows));
        $review = DoeStationReview::firstOrFail();

        $review->update([
            'status' => DoeStationReview::STATUS_MAPPED,
            'resolved_station_id' => $station->getKey(),
        ]);

        $this->import($this->batch('20260807', $rows));

        $this->assertSame(1, StationPrice::count());
        $this->assertSame($station->getKey(), StationPrice::firstOrFail()->station_id);
    }

    // -- delegation to the platform ------------------------------------------

    public function test_an_implausible_price_is_left_to_price_service(): void
    {
        // The importer does not second-guess it. A second set of plausibility
        // rules would be a second thing to keep in step.
        $this->station('Petron', 'Quezon City', 'EDSA Kamuning');

        $outcome = $this->import($this->batch('20260806', [
            ['Petron EDSA Kamuning', 'Petron', 'Quezon City', 'NCR', 'a', null, 99999.0, null],
        ]));

        $this->assertSame(0, $outcome->imported);
        $this->assertSame(1, $outcome->skipped);
        $this->assertSame(0, StationPrice::count());
        $this->assertNotEmpty($outcome->rejected);
    }

    public function test_a_grade_the_platform_does_not_sell_is_skipped(): void
    {
        // The DOE lists RON 100 and fuel_types has no such row. Mapping it onto
        // RON 97 would file one product's price under another.
        $this->station('Petron', 'Quezon City', 'EDSA Kamuning');

        $payload = $this->doeEnvelope(
            [$this->doeDataPayload('20260806', [
                ['Petron EDSA Kamuning', 'Petron', 'Quezon City', 'NCR', 'a', null, 70.00, null],
            ])],
            [$this->doeSchemaPayload([
                'qt_fgaojmiemc' => 'Timestamp',
                'qt_85e4fhiemc' => 'Gas Station',
                'qt_a1b2c3diemc' => 'Company',
                'qt_d4e5f6giemc' => 'City/Municipality',
                'qt_g7h8i9jiemc' => 'Province',
                'qt_z1x2c3viemc' => 'Address',
                'qt_m4n5o6piemc' => 'RON 91',
                // The column the fixture fills with 70.00 is published as RON 100.
                'qt_p7q8r9siemc' => 'RON 100',
                'qt_y7z8a9biemc' => 'Diesel',
            ])],
        );

        $batch = DoeImportBatch::create([
            'started_at' => now(),
            'status' => DoeImportBatch::STATUS_PENDING,
            'payload_hash' => hash('sha256', $payload),
            'raw_payload' => $payload,
        ]);

        $outcome = $this->import($batch);

        $this->assertSame(0, $outcome->imported);
        $this->assertArrayHasKey('gasoline_ron100', $outcome->unknownFuels);
    }

    public function test_a_dashboard_whose_labels_changed_fails_loudly(): void
    {
        // The failure mode that matters: a mapping that resolves nothing must
        // not report success having imported nothing.
        $payload = $this->doeEnvelope(
            [$this->doeDataPayload('20260806', [
                ['Petron EDSA', 'Petron', 'Quezon City', 'NCR', 'a', null, 62.40, null],
            ])],
            [$this->doeSchemaPayload(['qt_unknown0001' => 'Something Unrecognisable'])],
        );

        $batch = DoeImportBatch::create([
            'started_at' => now(),
            'status' => DoeImportBatch::STATUS_PENDING,
            'payload_hash' => 'x',
            'raw_payload' => $payload,
        ]);

        $outcome = $this->import($batch);

        $this->assertTrue($outcome->failed);
        $this->assertStringContainsString('mapping is unusable', (string) $batch->fresh()->error);
    }

    // -- bookkeeping ---------------------------------------------------------

    public function test_the_batch_records_what_happened(): void
    {
        $this->station('Petron', 'Quezon City', 'EDSA Kamuning');

        $batch = $this->batch('20260806', [
            ['Petron EDSA Kamuning', 'Petron', 'Quezon City', 'NCR', 'a', 58.20, 62.40, 56.85],
            ['Nowhere Fuels', 'Nowhere Fuels', 'Atlantis', 'Deep', 'b', null, 61.00, null],
        ]);

        $this->import($batch);
        $batch->refresh();

        $this->assertSame(DoeImportBatch::STATUS_IMPORTED, $batch->status);
        $this->assertSame(2, $batch->records_parsed);
        $this->assertSame(3, $batch->records_imported);
        $this->assertSame(1, $batch->stations_unmatched);
        $this->assertNotNull($batch->finished_at);
        $this->assertGreaterThan(0, $batch->logs()->count());
    }

    public function test_the_command_imports_pending_batches(): void
    {
        $this->station('Petron', 'Quezon City', 'EDSA Kamuning');
        $this->batch('20260806', [
            ['Petron EDSA Kamuning', 'Petron', 'Quezon City', 'NCR', 'a', null, 62.40, null],
        ]);

        $this->artisan('fip:doe-import')->assertExitCode(0);

        $this->assertSame(1, StationPrice::count());
    }

    public function test_the_command_can_replay_one_batch(): void
    {
        $this->station('Petron', 'Quezon City', 'EDSA Kamuning');
        $batch = $this->batch('20260806', [
            ['Petron EDSA Kamuning', 'Petron', 'Quezon City', 'NCR', 'a', null, 62.40, null],
        ]);

        $this->artisan('fip:doe-import')->assertExitCode(0);
        // Already imported; --replay reaches it anyway.
        $this->artisan('fip:doe-import', ['--replay' => $batch->id])->assertExitCode(0);

        $this->assertSame(1, StationPrice::count());
    }

    public function test_the_command_reports_nothing_to_do(): void
    {
        $this->artisan('fip:doe-import')
            ->expectsOutputToContain('Nothing to import')
            ->assertExitCode(0);
    }
}
