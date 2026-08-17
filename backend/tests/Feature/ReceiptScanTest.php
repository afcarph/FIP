<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Expense\Models\FuelPurchase;
use App\Domain\Fleet\Models\VehicleFuelReading;
use App\Domain\Pricing\Models\FuelType;
use App\Domain\User\Models\User;
use App\Domain\Vehicle\Models\Vehicle;
use App\Services\External\AiServiceClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * Receipt scanning produces a draft and nothing else.
 *
 * The tests that matter most here are the negative ones: that a scan never
 * writes a purchase, and never touches the fuel level. Both are things the
 * feature could easily have been built to do, and both would route around the
 * services that own those decisions.
 */
class ReceiptScanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatform();
        Storage::fake(config('filesystems.default'));
    }

    /** @param array<string, mixed> $overrides */
    private function mockScan(array $overrides = []): void
    {
        $payload = array_replace_recursive([
            'raw_text' => 'SEAOIL BGC 32ND STREET LITERS 42.30 PRICE/L 58.49 TOTAL 2,474.13',
            'litres' => ['value' => 42.30, 'confidence' => 0.95],
            'price_per_litre' => ['value' => 58.49, 'confidence' => 0.95],
            'total_cost' => ['value' => 2474.13, 'confidence' => 0.95],
            'purchased_at' => ['value' => now()->subDay()->toIso8601String(), 'confidence' => 0.7],
            'odometer' => ['value' => null, 'confidence' => 0.0],
            'station_hint' => ['value' => 'SEAOIL', 'confidence' => 0.8],
            'fuel_type' => ['value' => 'diesel', 'confidence' => 0.7],
            'overall_confidence' => 0.95,
            'warnings' => [],
        ], $overrides);

        $ai = Mockery::mock(AiServiceClient::class);
        $ai->shouldReceive('scanReceipt')->andReturn($payload);

        $this->app->instance(AiServiceClient::class, $ai);
    }

    private function receipt(): UploadedFile
    {
        return UploadedFile::fake()->image('receipt.jpg', 900, 1400);
    }

    // --------------------------------------------------------- happy path ---

    public function test_it_returns_a_draft_from_a_receipt(): void
    {
        $this->mockScan();
        $this->actingAsRole('user');

        $response = $this->postJson('/api/v1/expenses/scan-receipt', ['image' => $this->receipt()]);

        $this->assertApiSuccess($response);
        $this->assertSame(42.30, $response->json('data.draft.litres'));
        $this->assertSame(58.49, $response->json('data.draft.price_per_litre'));
        $this->assertSame(2474.13, $response->json('data.draft.total_cost'));
        $this->assertSame('SEAOIL', $response->json('data.draft.station_hint'));
        $this->assertFalse($response->json('data.needs_review'));
    }

    public function test_the_receipt_image_is_stored_for_the_purchase_to_reference(): void
    {
        $this->mockScan();
        $this->actingAsRole('user');

        $response = $this->postJson('/api/v1/expenses/scan-receipt', ['image' => $this->receipt()]);

        $path = $response->json('data.receipt_path');

        $this->assertNotNull($path);
        Storage::disk(config('filesystems.default'))->assertExists($path);
    }

    public function test_the_grade_is_mapped_onto_a_fuel_type(): void
    {
        $this->mockScan();
        $this->actingAsRole('user');

        $response = $this->postJson('/api/v1/expenses/scan-receipt', ['image' => $this->receipt()]);

        $this->assertSame(
            FuelType::where('code', 'diesel')->value('id'),
            $response->json('data.draft.fuel_type_id'),
        );
    }

    public function test_an_unreadable_grade_falls_back_to_the_vehicles_own_fuel_type(): void
    {
        $this->mockScan(['fuel_type' => ['value' => null]]);
        $user = $this->actingAsRole('user');
        $vehicle = Vehicle::factory()->create(['owner_id' => $user->id]);

        $response = $this->postJson('/api/v1/expenses/scan-receipt', [
            'image' => $this->receipt(),
            'vehicle_id' => $vehicle->id,
        ]);

        $this->assertSame($vehicle->fuel_type_id, $response->json('data.draft.fuel_type_id'));
    }

    // ------------------------------------------------------- the boundary ---

    public function test_scanning_never_creates_a_purchase(): void
    {
        $this->mockScan();
        $user = $this->actingAsRole('user');
        $vehicle = Vehicle::factory()->create(['owner_id' => $user->id]);

        $this->postJson('/api/v1/expenses/scan-receipt', [
            'image' => $this->receipt(),
            'vehicle_id' => $vehicle->id,
        ]);

        $this->assertSame(0, FuelPurchase::count(), 'A scan is a draft; only the user creates the record.');
    }

    public function test_scanning_never_touches_the_fuel_level(): void
    {
        $this->mockScan();
        $user = $this->actingAsRole('user');
        $vehicle = Vehicle::factory()->create(['owner_id' => $user->id]);

        $this->postJson('/api/v1/expenses/scan-receipt', [
            'image' => $this->receipt(),
            'vehicle_id' => $vehicle->id,
        ]);

        $fresh = $vehicle->fresh();

        $this->assertSame(0, VehicleFuelReading::count());
        $this->assertNull($fresh->current_fuel_pct, 'A receipt records a purchase, not a tank level.');
        $this->assertNull($fresh->fuel_level_at);
    }

    public function test_a_station_is_never_selected_automatically(): void
    {
        $this->mockScan();
        $this->actingAsRole('user');

        $response = $this->postJson('/api/v1/expenses/scan-receipt', ['image' => $this->receipt()]);

        $this->assertNull(
            $response->json('data.draft.station_id'),
            'A brand hint cannot distinguish two branches of the same brand.',
        );
    }

    // ---------------------------------------------------- low-quality reads ---

    public function test_a_low_confidence_scan_is_flagged_for_review(): void
    {
        $this->mockScan([
            'overall_confidence' => 0.4,
            'warnings' => ['Litres x price does not match the total.'],
        ]);
        $this->actingAsRole('user');

        $response = $this->postJson('/api/v1/expenses/scan-receipt', ['image' => $this->receipt()]);

        $this->assertTrue($response->json('data.needs_review'));
        $this->assertNotEmpty($response->json('data.warnings'));
    }

    public function test_a_scan_missing_the_amounts_is_flagged_for_review(): void
    {
        $this->mockScan([
            'litres' => ['value' => null],
            'total_cost' => ['value' => null],
        ]);
        $this->actingAsRole('user');

        $response = $this->postJson('/api/v1/expenses/scan-receipt', ['image' => $this->receipt()]);

        $this->assertTrue($response->json('data.needs_review'));
    }

    public function test_a_receipt_dated_in_the_future_is_discarded(): void
    {
        $this->mockScan(['purchased_at' => ['value' => now()->addYear()->toIso8601String()]]);
        $this->actingAsRole('user');

        $response = $this->postJson('/api/v1/expenses/scan-receipt', ['image' => $this->receipt()]);

        $this->assertNull(
            $response->json('data.draft.purchased_at'),
            'A future date is a misread year, not a real one.',
        );
    }

    public function test_an_odometer_below_the_vehicles_own_is_dropped_with_a_warning(): void
    {
        $this->mockScan(['odometer' => ['value' => 1000, 'confidence' => 0.8]]);
        $user = $this->actingAsRole('user');
        $vehicle = Vehicle::factory()->create(['owner_id' => $user->id, 'current_odometer' => 90_000]);

        $response = $this->postJson('/api/v1/expenses/scan-receipt', [
            'image' => $this->receipt(),
            'vehicle_id' => $vehicle->id,
        ]);

        $this->assertNull($response->json('data.draft.odometer'));
        $this->assertNotEmpty($response->json('data.warnings'));
    }

    // ------------------------------------------------------ authorisation ---

    public function test_it_requires_authentication(): void
    {
        $this->postJson('/api/v1/expenses/scan-receipt', ['image' => $this->receipt()])
            ->assertStatus(401);
    }

    public function test_it_rejects_a_scan_against_someone_elses_vehicle(): void
    {
        $this->mockScan();
        $this->actingAsRole('user');
        $vehicle = Vehicle::factory()->create(['owner_id' => User::factory()->create()->id]);

        $this->postJson('/api/v1/expenses/scan-receipt', [
            'image' => $this->receipt(),
            'vehicle_id' => $vehicle->id,
        ])->assertStatus(403);
    }

    public function test_it_rejects_a_non_image(): void
    {
        $this->mockScan();
        $this->actingAsRole('user');

        $this->assertApiValidationErrors(
            $this->postJson('/api/v1/expenses/scan-receipt', [
                'image' => UploadedFile::fake()->create('statement.pdf', 200, 'application/pdf'),
            ]),
            'image',
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
