<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Ai\Models\FraudAlert;
use App\Domain\Ai\Services\FraudDetectionService;
use App\Domain\Expense\Models\FuelPurchase;
use App\Domain\Vehicle\Models\Vehicle;
use App\Services\External\AiServiceClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * The rule tier of fraud detection is deterministic, so it can be asserted
 * exactly. These tests pin the behaviour that fleet managers rely on: an
 * overfill, an impossible efficiency jump and a clean transaction must each
 * produce the expected outcome.
 */
class FraudDetectionServiceTest extends TestCase
{
    use RefreshDatabase;

    private FraudDetectionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatform();

        // The rule tier never calls the AI service; a null client proves it.
        $this->service = new FraudDetectionService(Mockery::mock(AiServiceClient::class));
    }

    public function test_it_flags_a_fill_exceeding_tank_capacity(): void
    {
        $vehicle = Vehicle::factory()->create([
            'tank_capacity' => 60.0,
            'baseline_km_per_litre' => 10.0,
        ]);

        $purchase = FuelPurchase::factory()->for($vehicle)->create([
            'litres' => 85.0,          // 41% over capacity
            'km_per_litre' => 10.1,
            'purchased_at' => now(),
        ]);

        $alert = $this->service->screen($purchase);

        $this->assertNotNull($alert, 'An overfill should raise an alert.');
        $this->assertSame('overfill', $alert->alert_type);
        $this->assertGreaterThanOrEqual(0.65, $alert->score);
        $this->assertSame(FraudAlert::STATUS_OPEN, $alert->status);
        $this->assertSame(85.0, $alert->evidence['signals'][0]['detail']['litres']);
    }

    public function test_it_flags_impossible_efficiency_as_a_ghost_refuel(): void
    {
        $vehicle = Vehicle::factory()->create([
            'tank_capacity' => 60.0,
            'baseline_km_per_litre' => 9.0,
        ]);

        // 16 km/L on a van that normally returns 9 — the distance was driven
        // but the fuel was not burned by this vehicle.
        $purchase = FuelPurchase::factory()->for($vehicle)->create([
            'litres' => 45.0,
            'km_per_litre' => 16.0,
            'purchased_at' => now(),
        ]);

        $alert = $this->service->screen($purchase);

        $this->assertNotNull($alert);
        $this->assertSame('ghost_refuel', $alert->alert_type);
    }

    public function test_it_flags_an_odometer_rollback(): void
    {
        $vehicle = Vehicle::factory()->create(['tank_capacity' => 60.0, 'baseline_km_per_litre' => 10.0]);

        FuelPurchase::factory()->for($vehicle)->create([
            'odometer' => 50_000,
            'purchased_at' => now()->subDays(7),
        ]);

        $purchase = FuelPurchase::factory()->for($vehicle)->create([
            'odometer' => 49_000,       // went backwards
            'litres' => 40.0,
            'purchased_at' => now(),
        ]);

        $alert = $this->service->screen($purchase);

        $this->assertNotNull($alert);
        $this->assertContains(
            $alert->alert_type,
            ['odometer_rollback', 'ghost_refuel'],
            'A rollback must surface as a rollback or, at minimum, an efficiency anomaly.',
        );
    }

    public function test_a_normal_fill_up_produces_no_alert(): void
    {
        $vehicle = Vehicle::factory()->create([
            'tank_capacity' => 60.0,
            'baseline_km_per_litre' => 10.0,
        ]);

        $purchase = FuelPurchase::factory()->for($vehicle)->create([
            'litres' => 45.0,
            'km_per_litre' => 9.8,      // within 2% of baseline
            'odometer' => 51_000,
            'purchased_at' => now(),
        ]);

        $this->assertNull($this->service->screen($purchase));
        $this->assertSame(0.0, (float) $purchase->fresh()->anomaly_score);
    }

    public function test_combined_weak_signals_escalate_severity(): void
    {
        $vehicle = Vehicle::factory()->create([
            'tank_capacity' => 60.0,
            'baseline_km_per_litre' => 10.0,
        ]);

        FuelPurchase::factory()->for($vehicle)->create(['purchased_at' => now()->subMinutes(10), 'odometer' => 50_000]);

        // Slightly over capacity AND a rapid repeat fill: neither alone would
        // be conclusive, but together they should clear the threshold.
        $purchase = FuelPurchase::factory()->for($vehicle)->create([
            'litres' => 66.0,
            'km_per_litre' => 9.9,
            'odometer' => 50_050,
            'purchased_at' => now(),
        ]);

        $alert = $this->service->screen($purchase);

        $this->assertNotNull($alert);
        $this->assertGreaterThan(
            0.65,
            $alert->score,
            'Noisy-OR combination should push two weak signals past the threshold.',
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
