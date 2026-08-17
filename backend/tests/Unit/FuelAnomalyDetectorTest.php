<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Ai\Models\FraudAlert;
use App\Domain\Expense\Models\FuelPurchase;
use App\Domain\Fleet\Services\FuelAnomalyDetector;
use App\Domain\Fleet\Services\FuelLevelService;
use App\Domain\User\Models\Company;
use App\Domain\User\Models\User;
use App\Domain\Vehicle\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Detection is only useful if it is quiet.
 *
 * Most of these tests assert that nothing fires — on ordinary consumption, on
 * an explained refill, on a slow drift. A detector that flags a delivery round
 * as theft is worse than no detector, because the first week of false alarms
 * teaches everyone to ignore the second week's real one.
 */
class FuelAnomalyDetectorTest extends TestCase
{
    use RefreshDatabase;

    private FuelLevelService $levels;

    private Vehicle $vehicle;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatform();

        config([
            'fip.fuel_anomaly.drop_pct' => 15.0,
            'fip.fuel_anomaly.drop_window_minutes' => 15,
            'fip.fuel_anomaly.gain_pct' => 10.0,
            'fip.fuel_anomaly.sensor_reversal_pct' => 15.0,
            'fip.fuel_anomaly.purchase_match_minutes' => 90,
            'fip.fuel_anomaly.score_threshold' => 0.65,
        ]);

        $this->levels = new FuelLevelService(new FuelAnomalyDetector);

        $this->vehicle = Vehicle::factory()->forCompany(Company::factory()->create()->id)->create([
            'tank_capacity' => 60.0,
        ]);
    }

    private function record(float $pct, int $minutesAgo, array $extra = []): void
    {
        $this->levels->record($this->vehicle, [
            'fuel_pct' => $pct,
            'recorded_at' => now()->subMinutes($minutesAgo),
        ] + $extra);
    }

    // ------------------------------------------------------- the headline ---

    public function test_a_steep_unexplained_drop_raises_an_alert(): void
    {
        // The case the whole feature exists for: 80% to 40% in a minute.
        $this->record(80, 2);
        $this->record(40, 1);

        $alert = FraudAlert::first();

        $this->assertNotNull($alert);
        $this->assertSame('abnormal_fuel_drop', $alert->alert_type);
        $this->assertSame(FraudAlert::STATUS_OPEN, $alert->status);
        $this->assertGreaterThanOrEqual(0.65, $alert->score);
    }

    public function test_the_alert_records_what_it_measured(): void
    {
        $this->record(80, 2);
        $this->record(40, 1);

        $detail = FraudAlert::first()->evidence['signals'][0]['detail'];

        $this->assertSame(80.0, $detail['from_pct']);
        $this->assertSame(40.0, $detail['to_pct']);
        $this->assertSame(40.0, $detail['dropped_pct']);
        // 40% of a 60 L tank, so an operator sees litres and not just points.
        $this->assertSame(24.0, $detail['litres_lost']);
    }

    public function test_the_alert_links_the_reading_that_raised_it(): void
    {
        $this->record(80, 2);
        $this->record(40, 1);

        $alert = FraudAlert::first();

        $this->assertNotNull($alert->vehicle_fuel_reading_id);
        $this->assertSame(40.0, $alert->reading->fuel_pct);
    }

    public function test_it_is_never_described_as_theft(): void
    {
        $this->record(80, 2);
        $this->record(40, 1);

        $alert = FraudAlert::first();

        $this->assertStringNotContainsStringIgnoringCase('theft', $alert->alert_type);
        $this->assertStringNotContainsStringIgnoringCase('stolen', $alert->alert_type);
        $this->assertStringNotContainsStringIgnoringCase('siphon', $alert->alert_type);
    }

    public function test_a_bigger_drop_scores_higher(): void
    {
        $this->record(90, 4);
        $this->record(70, 3);
        $modest = FraudAlert::latest('id')->first()->score;

        FraudAlert::query()->delete();

        $this->record(60, 2);
        $this->record(5, 1);
        $severe = FraudAlert::latest('id')->first()->score;

        $this->assertGreaterThan($modest, $severe);
    }

    // ------------------------------------------------------------ quiet ---

    public function test_ordinary_consumption_raises_nothing(): void
    {
        foreach ([80, 78, 76, 74, 72] as $index => $pct) {
            $this->record((float) $pct, 50 - ($index * 10));
        }

        $this->assertSame(0, FraudAlert::count(), 'Driving is not an anomaly.');
    }

    public function test_a_large_fall_spread_over_hours_raises_nothing(): void
    {
        // The same 40 points as the headline case, but over a working day.
        $this->record(80, 600);
        $this->record(40, 60);

        $this->assertSame(
            0,
            FraudAlert::count(),
            '40 points across a shift is a delivery round, not a siphon.',
        );
    }

    public function test_the_first_reading_raises_nothing(): void
    {
        $this->record(20, 1);

        $this->assertSame(0, FraudAlert::count(), 'Nothing to be abnormal against.');
    }

    public function test_a_refill_explained_by_a_purchase_raises_nothing(): void
    {
        $this->record(15, 20);

        FuelPurchase::create([
            'vehicle_id' => $this->vehicle->id,
            'user_id' => User::factory()->create()->id,
            'fuel_type_id' => $this->vehicle->fuel_type_id,
            'litres' => 45,
            'price_per_litre' => 58.0,
            'total_cost' => 2610.0,
            'purchased_at' => now()->subMinutes(12),
        ]);

        $this->record(90, 10);

        $this->assertSame(0, FraudAlert::count(), 'A logged fill-up explains the rise.');
    }

    public function test_a_drop_during_a_logged_purchase_window_raises_nothing(): void
    {
        // A tank drained during a service visit that was recorded as a
        // purchase is paperwork, not a loss.
        $this->record(80, 20);

        FuelPurchase::create([
            'vehicle_id' => $this->vehicle->id,
            'user_id' => User::factory()->create()->id,
            'fuel_type_id' => $this->vehicle->fuel_type_id,
            'litres' => 40,
            'price_per_litre' => 58.0,
            'total_cost' => 2320.0,
            'purchased_at' => now()->subMinutes(11),
        ]);

        $this->record(30, 10);

        $this->assertSame(0, FraudAlert::count());
    }

    // ------------------------------------------------------- other rules ---

    public function test_fuel_appearing_without_a_purchase_is_flagged(): void
    {
        $this->record(20, 2);
        $this->record(85, 1);

        $alert = FraudAlert::first();

        $this->assertNotNull($alert);
        $this->assertContains(
            $alert->alert_type,
            ['unexplained_fuel_gain', 'abnormal_fuel_drop', 'sensor_anomaly'],
        );
        $this->assertSame(
            'unexplained_fuel_gain',
            collect($alert->evidence['signals'])->pluck('type')->first(),
        );
    }

    public function test_a_fall_immediately_undone_reads_as_a_sensor_fault(): void
    {
        $this->record(80, 3);
        $this->record(40, 2);   // steep fall
        $this->record(78, 1);   // and back again, with nothing bought

        $types = FraudAlert::query()->pluck('alert_type')->all();

        $this->assertContains('sensor_anomaly', $types);
    }

    // ------------------------------------------------------------ wiring ---

    public function test_the_alert_inherits_the_vehicles_company_and_fleet(): void
    {
        $this->record(80, 2);
        $this->record(40, 1);

        $alert = FraudAlert::first();

        $this->assertSame($this->vehicle->company_id, $alert->company_id);
        $this->assertSame($this->vehicle->fleet_id, $alert->fleet_id);
        $this->assertSame($this->vehicle->id, $alert->vehicle_id);
    }

    public function test_a_simulated_drop_still_raises_an_alert(): void
    {
        // The simulator exists to prove detection works, so its readings must
        // travel the same path. The alert records the source so a demo alert
        // is never mistaken for a real one.
        $this->record(80, 2, ['source' => 'simulated']);
        $this->record(35, 1, ['source' => 'simulated']);

        $alert = FraudAlert::first();

        $this->assertNotNull($alert);
        $this->assertSame('simulated', $alert->evidence['source']);
    }

    public function test_readings_below_the_threshold_stay_silent(): void
    {
        config(['fip.fuel_anomaly.score_threshold' => 0.99]);

        $this->record(80, 2);
        $this->record(40, 1);

        $this->assertSame(0, FraudAlert::count(), 'The threshold is configurable and respected.');
    }
}
