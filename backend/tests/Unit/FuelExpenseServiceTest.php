<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Expense\Contracts\FraudScreener;
use App\Domain\Expense\Services\FuelExpenseService;
use App\Domain\User\Models\User;
use App\Domain\Vehicle\Models\Vehicle;
use App\Support\Exceptions\DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Derived metrics are computed once at write time and everything downstream
 * trusts them, so the arithmetic is worth pinning precisely.
 */
class FuelExpenseServiceTest extends TestCase
{
    use RefreshDatabase;

    private FuelExpenseService $service;

    private User $user;

    private Vehicle $vehicle;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatform();

        $fraud = Mockery::mock(FraudScreener::class);
        $fraud->shouldReceive('screen')->andReturnNull();

        $this->service = new FuelExpenseService($fraud);
        $this->user = User::factory()->create();
        $this->vehicle = Vehicle::factory()->create([
            'owner_id' => $this->user->id,
            'tank_capacity' => 60.0,
            'current_odometer' => 50_000,
        ]);
    }

    public function test_it_computes_efficiency_between_two_full_tanks(): void
    {
        $this->service->record($this->user, $this->vehicle, [
            'litres' => 40,
            'price_per_litre' => 58.00,
            'odometer' => 50_000,
            'is_full_tank' => true,
            'purchased_at' => now()->subDays(10)->toDateTimeString(),
        ]);

        // 400 km on 40 L ⇒ exactly 10 km/L.
        $second = $this->service->record($this->user, $this->vehicle, [
            'litres' => 40,
            'price_per_litre' => 58.00,
            'odometer' => 50_400,
            'is_full_tank' => true,
            'purchased_at' => now()->toDateTimeString(),
        ]);

        $this->assertSame(400.0, (float) $second->distance_since_last);
        $this->assertSame(10.0, (float) $second->km_per_litre);
        $this->assertSame(5.80, round((float) $second->cost_per_km, 2));
    }

    public function test_the_first_fill_up_has_no_efficiency_figure(): void
    {
        $purchase = $this->service->record($this->user, $this->vehicle, [
            'litres' => 40,
            'price_per_litre' => 58.00,
            'odometer' => 50_000,
            'purchased_at' => now()->toDateTimeString(),
        ]);

        $this->assertNull($purchase->distance_since_last);
        $this->assertNull($purchase->km_per_litre);
    }

    public function test_a_partial_fill_produces_no_km_per_litre(): void
    {
        $this->service->record($this->user, $this->vehicle, [
            'litres' => 40, 'price_per_litre' => 58.00, 'odometer' => 50_000,
            'is_full_tank' => true, 'purchased_at' => now()->subDays(5)->toDateTimeString(),
        ]);

        $partial = $this->service->record($this->user, $this->vehicle, [
            'litres' => 20, 'price_per_litre' => 58.00, 'odometer' => 50_200,
            'is_full_tank' => false, 'purchased_at' => now()->toDateTimeString(),
        ]);

        $this->assertNotNull($partial->distance_since_last);
        $this->assertNull(
            $partial->km_per_litre,
            'Tank-to-tank efficiency is only meaningful between two brim-full fills.',
        );
    }

    public function test_it_rejects_a_total_that_does_not_match_the_line_items(): void
    {
        $this->expectException(DomainException::class);

        $this->service->record($this->user, $this->vehicle, [
            'litres' => 40,
            'price_per_litre' => 58.00,   // ⇒ ₱2,320
            'total_cost' => 999.00,       // clearly wrong
            'purchased_at' => now()->toDateTimeString(),
        ]);
    }

    public function test_it_tolerates_receipt_rounding(): void
    {
        $purchase = $this->service->record($this->user, $this->vehicle, [
            'litres' => 40,
            'price_per_litre' => 58.00,
            'total_cost' => 2320.50,      // ₱0.50 of pump rounding
            'purchased_at' => now()->toDateTimeString(),
        ]);

        $this->assertSame(2320.50, (float) $purchase->total_cost);
    }

    public function test_it_rejects_an_odometer_rollback(): void
    {
        $this->service->record($this->user, $this->vehicle, [
            'litres' => 40, 'price_per_litre' => 58.00, 'odometer' => 50_000,
            'purchased_at' => now()->subDays(5)->toDateTimeString(),
        ]);

        $this->expectException(DomainException::class);

        $this->service->record($this->user, $this->vehicle, [
            'litres' => 40, 'price_per_litre' => 58.00, 'odometer' => 49_000,
            'purchased_at' => now()->toDateTimeString(),
        ]);
    }

    public function test_it_advances_the_vehicle_odometer(): void
    {
        $this->service->record($this->user, $this->vehicle, [
            'litres' => 40, 'price_per_litre' => 58.00, 'odometer' => 50_800,
            'purchased_at' => now()->toDateTimeString(),
        ]);

        $this->assertSame(50_800.0, (float) $this->vehicle->fresh()->current_odometer);
        $this->assertDatabaseHas('odometer_readings', ['reading' => 50_800, 'source' => 'fuel_log']);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
