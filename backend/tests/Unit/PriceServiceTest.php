<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Pricing\Models\FuelPriceHistory;
use App\Domain\Pricing\Models\FuelType;
use App\Domain\Pricing\Models\StationPrice;
use App\Domain\Pricing\Repositories\PriceRepository;
use App\Domain\Pricing\Services\PriceService;
use App\Domain\Station\Models\GasStation;
use App\Support\Exceptions\DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * PriceService is the single write path for live prices, so its source
 * precedence rules are load-bearing: a crowd guess must never silently
 * overwrite an operator's own board price.
 */
class PriceServiceTest extends TestCase
{
    use RefreshDatabase;

    private PriceService $service;

    private GasStation $station;

    private FuelType $fuelType;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatform();
        Queue::fake();

        $this->service = new PriceService(app(PriceRepository::class));
        $this->station = GasStation::factory()->create();
        $this->fuelType = FuelType::where('code', 'diesel')->firstOrFail();
    }

    public function test_it_records_a_price_and_archives_history(): void
    {
        $price = $this->service->recordPrice($this->station, $this->fuelType->id, 57.50, 'operator');

        $this->assertSame(57.50, (float) $price->price);
        $this->assertNull($price->previous_price);
        $this->assertNotNull($price->verified_at, 'Operator prices are trusted and marked verified.');

        $this->assertDatabaseCount('fuel_price_history', 1);
        $this->assertSame(57.50, (float) FuelPriceHistory::first()->price);
    }

    public function test_it_tracks_the_previous_price_on_update(): void
    {
        $this->service->recordPrice($this->station, $this->fuelType->id, 57.50, 'operator');
        $updated = $this->service->recordPrice($this->station, $this->fuelType->id, 58.20, 'operator');

        $this->assertSame(57.50, (float) $updated->previous_price);
        $this->assertSame(58.20, (float) $updated->price);
        $this->assertDatabaseCount('fuel_price_history', 2);
        $this->assertSame(1, StationPrice::count(), 'Only one live row per station/fuel pair.');
    }

    public function test_a_crowd_report_does_not_overwrite_a_fresh_operator_price(): void
    {
        $this->service->recordPrice($this->station, $this->fuelType->id, 57.50, 'operator');

        $result = $this->service->recordPrice($this->station, $this->fuelType->id, 55.00, 'crowd', 0.9);

        $this->assertSame(
            57.50,
            (float) $result->price,
            'A lower-ranked source must not displace a fresh authoritative price.',
        );
    }

    public function test_a_crowd_report_replaces_a_stale_operator_price(): void
    {
        $staleAt = now()->subHours((int) config('fip.pricing.stale_after_hours') + 6);

        $this->service->recordPrice($this->station, $this->fuelType->id, 57.50, 'operator', 1.0, null, $staleAt);

        $result = $this->service->recordPrice($this->station, $this->fuelType->id, 55.00, 'crowd', 0.9);

        $this->assertSame(55.00, (float) $result->price, 'A stale price should yield to fresher crowd data.');
    }

    public function test_an_older_reading_never_overwrites_a_newer_one(): void
    {
        $this->service->recordPrice($this->station, $this->fuelType->id, 58.00, 'operator');

        $result = $this->service->recordPrice(
            $this->station,
            $this->fuelType->id,
            57.00,
            'operator',
            1.0,
            null,
            now()->subDay(),
        );

        $this->assertSame(58.00, (float) $result->price);
    }

    public function test_it_rejects_an_implausible_price(): void
    {
        $this->expectException(DomainException::class);

        $this->service->recordPrice($this->station, $this->fuelType->id, 1500.00, 'operator');
    }

    public function test_recording_the_same_reading_twice_archives_it_once(): void
    {
        // Identity, not precedence. `supersedes()` correctly says an equal
        // source at an equal time with equal confidence may replace the stored
        // price — but for a reading that is byte-for-byte the one already held,
        // "replacing" it means a second, duplicate row in fuel_price_history.
        //
        // This is the invariant every idempotent caller depends on: a retried
        // job, an overlapping cron, a DOE batch replayed from its stored
        // payload after a parser fix.
        $effectiveAt = now()->subHour();

        $this->service->recordPrice($this->station, $this->fuelType->id, 56.85, 'doe', 1.0, null, $effectiveAt);
        $before = FuelPriceHistory::count();

        $this->service->recordPrice($this->station, $this->fuelType->id, 56.85, 'doe', 1.0, null, $effectiveAt);

        $this->assertSame($before, FuelPriceHistory::count());
        $this->assertSame(1, StationPrice::count());
    }

    public function test_a_changed_price_at_the_same_time_is_still_recorded(): void
    {
        // The correction case. Suppressing a genuine restatement because the
        // effective time matched would silently drop a fix.
        $effectiveAt = now()->subHour();

        $this->service->recordPrice($this->station, $this->fuelType->id, 56.85, 'doe', 1.0, null, $effectiveAt);
        $result = $this->service->recordPrice($this->station, $this->fuelType->id, 57.10, 'doe', 1.0, null, $effectiveAt);

        $this->assertSame(57.10, (float) $result->price);
        $this->assertSame(2, FuelPriceHistory::count());
    }

    public function test_the_same_price_from_a_different_source_is_still_recorded(): void
    {
        // An operator confirming what the DOE published is new information —
        // it changes which source the stored price is attributed to.
        $effectiveAt = now()->subHour();

        $this->service->recordPrice($this->station, $this->fuelType->id, 56.85, 'doe', 1.0, null, $effectiveAt);
        $result = $this->service->recordPrice($this->station, $this->fuelType->id, 56.85, 'operator', 1.0, null, $effectiveAt);

        $this->assertSame('operator', $result->source);
    }
}
