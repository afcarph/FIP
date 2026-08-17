<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Expense\Models\FuelPurchase;
use App\Domain\Fleet\Models\Driver;
use App\Domain\User\Models\Company;
use App\Domain\User\Models\UserDevice;
use App\Domain\Vehicle\Models\Vehicle;
use App\Domain\Vehicle\Models\VehicleAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * First-setup progress.
 *
 * The property worth protecting is that every step is *derived*. A stored
 * checklist would congratulate a company for a vehicle it has since deleted,
 * and the first anybody would know is a customer being told they are set up
 * while their dashboard shows nothing.
 */
class OnboardingTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatform();

        $this->company = Company::factory()->create();
    }

    /** @return array<string, mixed> */
    private function progress(): array
    {
        return $this->getJson('/api/v1/fleet/onboarding')->assertStatus(200)->json('data');
    }

    /** No DriverFactory in this suite; drivers are created directly. */
    private function driver(): Driver
    {
        return Driver::create([
            'company_id' => $this->company->id,
            'first_name' => 'Pilot',
            'last_name' => 'Driver',
            'status' => 'active',
        ]);
    }

    private function step(array $body, string $key): array
    {
        return collect($body['steps'])->firstWhere('key', $key);
    }

    public function test_a_brand_new_company_has_everything_left_to_do(): void
    {
        $this->actingAsRole('company_manager', ['company_id' => $this->company->id]);

        $body = $this->progress();

        $this->assertTrue($body['applies']);
        $this->assertSame(0, $body['completed']);
        $this->assertFalse($body['is_complete']);
        $this->assertCount(5, $body['steps']);
    }

    public function test_adding_a_vehicle_completes_that_step_and_no_other(): void
    {
        Vehicle::factory()->create(['company_id' => $this->company->id]);
        $this->actingAsRole('company_manager', ['company_id' => $this->company->id]);

        $body = $this->progress();

        $this->assertTrue($this->step($body, 'vehicle')['done']);
        $this->assertFalse($this->step($body, 'driver')['done']);
        $this->assertSame(1, $body['completed']);
    }

    public function test_progress_falls_back_when_the_records_go(): void
    {
        // The whole reason it is derived. A stored tick would still be there.
        $vehicle = Vehicle::factory()->create(['company_id' => $this->company->id]);
        $this->actingAsRole('company_manager', ['company_id' => $this->company->id]);

        $this->assertTrue($this->step($this->progress(), 'vehicle')['done']);

        $vehicle->delete();

        $this->assertFalse($this->step($this->progress(), 'vehicle')['done']);
    }

    public function test_a_browser_registration_does_not_count_as_a_device(): void
    {
        // A web row is a session identity. It can never report a position, so
        // ticking "get the app on a phone" for one would be false.
        $user = $this->actingAsRole('company_manager', ['company_id' => $this->company->id]);

        UserDevice::create([
            'user_id' => $user->getKey(),
            'device_uuid' => 'a-browser',
            'platform' => 'web',
        ]);

        $this->assertFalse($this->step($this->progress(), 'device')['done']);

        UserDevice::create([
            'user_id' => $user->getKey(),
            'device_uuid' => 'a-handset',
            'platform' => 'android',
        ]);

        $this->assertTrue($this->step($this->progress(), 'device')['done']);
    }

    public function test_a_released_assignment_does_not_count(): void
    {
        // "Put a driver in a vehicle" is about the current state of the fleet,
        // not about whether it ever happened once.
        $vehicle = Vehicle::factory()->create(['company_id' => $this->company->id]);
        $driver = $this->driver();

        VehicleAssignment::create([
            'vehicle_id' => $vehicle->getKey(),
            'driver_id' => $driver->getKey(),
            'assigned_at' => now()->subDay(),
            'released_at' => now(),
        ]);

        $this->actingAsRole('company_manager', ['company_id' => $this->company->id]);

        $this->assertFalse($this->step($this->progress(), 'assignment')['done']);
    }

    public function test_everything_done_reports_complete(): void
    {
        $user = $this->actingAsRole('company_manager', ['company_id' => $this->company->id]);
        $vehicle = Vehicle::factory()->create(['company_id' => $this->company->id]);
        $driver = $this->driver();

        VehicleAssignment::create([
            'vehicle_id' => $vehicle->getKey(),
            'driver_id' => $driver->getKey(),
            'assigned_at' => now(),
        ]);

        UserDevice::create([
            'user_id' => $user->getKey(),
            'device_uuid' => 'a-handset',
            'platform' => 'ios',
        ]);

        FuelPurchase::factory()->create(['vehicle_id' => $vehicle->getKey()]);

        $body = $this->progress();

        $this->assertSame(5, $body['completed']);
        $this->assertTrue($body['is_complete']);
    }

    // ------------------------------------------------------- who may read ---

    public function test_another_companys_records_do_not_count_towards_progress(): void
    {
        $stranger = Company::factory()->create();
        Vehicle::factory()->count(3)->create(['company_id' => $stranger->id]);
        Driver::create([
            'company_id' => $stranger->id,
            'first_name' => 'Someone',
            'last_name' => 'Else',
            'status' => 'active',
        ]);

        $this->actingAsRole('company_manager', ['company_id' => $this->company->id]);

        $this->assertSame(0, $this->progress()['completed']);
    }

    public function test_a_driver_cannot_read_the_companys_setup_progress(): void
    {
        // Same gate as the rest of the fleet: a driver holds no fleet.view.
        $this->actingAsRole('driver', ['company_id' => $this->company->id]);

        $this->getJson('/api/v1/fleet/onboarding')->assertStatus(403);
    }

    public function test_a_platform_administrator_is_told_it_does_not_apply(): void
    {
        $this->actingAsRole('super_admin', ['company_id' => null]);

        $body = $this->getJson('/api/v1/fleet/onboarding')->assertStatus(200)->json('data');

        $this->assertFalse($body['applies']);
        $this->assertArrayNotHasKey('steps', $body);
    }

    public function test_reading_progress_requires_authentication(): void
    {
        $this->getJson('/api/v1/fleet/onboarding')->assertStatus(401);
    }
}
