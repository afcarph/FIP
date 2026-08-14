<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Pricing\Models\FuelType;
use App\Domain\User\Models\Company;
use App\Domain\User\Models\User;
use App\Domain\User\Models\UserDevice;
use App\Domain\User\Services\SubscriptionLimitService;
use App\Domain\Vehicle\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Subscription capacity.
 *
 * Two properties matter more than the numbers, and both are asserted here:
 * a full plan refuses only *new* records, and nothing already created is ever
 * removed, hidden or made read-only by a tier. A driver must not lose their
 * vehicle mid-shift because a plan changed.
 */
class SubscriptionLimitTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatform();

        $this->company = Company::factory()->create(['subscription_tier' => 'free']);

        config([
            'fip.subscription.default_tier' => 'free',
            'fip.subscription.tiers' => [
                'free' => ['vehicles' => 2, 'seats' => 2, 'devices' => 1],
                'business' => ['vehicles' => 10, 'seats' => 10, 'devices' => 10],
                'enterprise' => ['vehicles' => null, 'seats' => null, 'devices' => null],
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function vehiclePayload(string $plate): array
    {
        return [
            'plate_number' => $plate,
            'vehicle_type' => 'van',
            'fuel_type_id' => FuelType::query()->value('id'),
            'tank_capacity' => 50,
        ];
    }

    // ------------------------------------------------------------ vehicles ---

    public function test_a_company_may_create_up_to_its_vehicle_limit(): void
    {
        $this->actingAsRole('fleet_manager', ['company_id' => $this->company->id]);

        $this->postJson('/api/v1/vehicles', $this->vehiclePayload('AAA 1111'))->assertStatus(201);
        $this->postJson('/api/v1/vehicles', $this->vehiclePayload('AAA 2222'))->assertStatus(201);

        $this->assertSame(2, Vehicle::where('company_id', $this->company->id)->count());
    }

    public function test_creating_beyond_the_limit_is_refused_with_402(): void
    {
        $this->actingAsRole('fleet_manager', ['company_id' => $this->company->id]);
        Vehicle::factory()->count(2)->forCompany($this->company->id)->create();

        $response = $this->postJson('/api/v1/vehicles', $this->vehiclePayload('AAA 3333'));

        // 402, not 403: the caller is authorised and the request is valid.
        // What is missing is capacity, and a client can offer an upgrade.
        $response->assertStatus(402);
        $this->assertSame('subscription_limit_reached', $response->json('error.code'));
        $this->assertSame(2, Vehicle::where('company_id', $this->company->id)->count());
    }

    public function test_the_refusal_names_the_plan_and_the_numbers(): void
    {
        // A message a person can act on: which plan, what it allows, what is
        // in use. "Limit reached" alone tells an operator nothing.
        $this->actingAsRole('fleet_manager', ['company_id' => $this->company->id]);
        Vehicle::factory()->count(2)->forCompany($this->company->id)->create();

        $message = $this->postJson('/api/v1/vehicles', $this->vehiclePayload('AAA 3333'))
            ->json('error.message');

        $this->assertStringContainsString('free', $message);
        $this->assertStringContainsString('2', $message);
        $this->assertStringContainsString('Existing records are unaffected', $message);
    }

    public function test_an_unlimited_tier_never_refuses(): void
    {
        $this->company->update(['subscription_tier' => 'enterprise']);
        $this->actingAsRole('fleet_manager', ['company_id' => $this->company->id]);
        Vehicle::factory()->count(20)->forCompany($this->company->id)->create();

        $this->postJson('/api/v1/vehicles', $this->vehiclePayload('AAA 9999'))->assertStatus(201);
    }

    public function test_an_unknown_tier_falls_back_to_the_default_rather_than_unlimited(): void
    {
        // A typo in a tier name must cost capacity that can be asked for, not
        // silently hand out an unbounded allowance.
        $this->company->update(['subscription_tier' => 'platinum-deluxe']);
        $this->actingAsRole('fleet_manager', ['company_id' => $this->company->id]);
        Vehicle::factory()->count(2)->forCompany($this->company->id)->create();

        $this->postJson('/api/v1/vehicles', $this->vehiclePayload('AAA 3333'))->assertStatus(402);
    }

    // -------------------------------------------------- downgrade behaviour ---

    public function test_a_company_over_its_limit_keeps_everything_it_has(): void
    {
        // The downgrade case. Five vehicles on a plan that allows two: all five
        // remain readable and updatable, only creation is refused.
        $this->actingAsRole('fleet_manager', ['company_id' => $this->company->id]);
        $vehicles = Vehicle::factory()->count(5)->forCompany($this->company->id)->create();

        $this->getJson('/api/v1/vehicles?per_page=50')->assertStatus(200)
            ->assertJsonCount(5, 'data');

        $this->patchJson("/api/v1/vehicles/{$vehicles->first()->getKey()}", ['nickname' => 'Still mine'])
            ->assertStatus(200);

        $this->postJson('/api/v1/vehicles', $this->vehiclePayload('AAA 6666'))->assertStatus(402);

        $this->assertSame(5, Vehicle::where('company_id', $this->company->id)->count());
    }

    // --------------------------------------------------------------- seats ---

    public function test_seats_are_limited_by_tier(): void
    {
        // setUp's acting user occupies the first seat of two.
        $this->actingAsRole('company_manager', ['company_id' => $this->company->id]);
        User::factory()->create(['company_id' => $this->company->id]);

        $this->postJson('/api/v1/admin/users', [
            'first_name' => 'Third',
            'last_name' => 'Person',
            'email' => 'third@example.test',
            'password' => 'S3cure-Passphrase!x9',
            'company_id' => $this->company->id,
            'roles' => ['driver'],
        ])->assertStatus(402);
    }

    // ------------------------------------------------------------- devices ---

    public function test_devices_are_limited_by_tier(): void
    {
        $user = $this->actingAsRole('driver', ['company_id' => $this->company->id]);

        $this->postJson('/api/v1/devices', ['device_uuid' => 'phone-one', 'platform' => 'ios'])
            ->assertStatus(201);

        $this->postJson('/api/v1/devices', ['device_uuid' => 'phone-two', 'platform' => 'ios'])
            ->assertStatus(402);

        $this->assertSame(1, UserDevice::where('user_id', $user->getKey())->count());
    }

    public function test_re_registering_the_same_device_is_not_charged_again(): void
    {
        // Registration is idempotent, and a driver re-running setup on the same
        // phone must not be refused for capacity.
        $this->actingAsRole('driver', ['company_id' => $this->company->id]);

        $this->postJson('/api/v1/devices', ['device_uuid' => 'phone-one', 'platform' => 'ios'])
            ->assertStatus(201);
        $this->postJson('/api/v1/devices', ['device_uuid' => 'phone-one', 'platform' => 'ios'])
            ->assertStatus(201);
    }

    public function test_a_revoked_device_frees_its_slot(): void
    {
        $user = $this->actingAsRole('driver', ['company_id' => $this->company->id]);

        $this->postJson('/api/v1/devices', ['device_uuid' => 'phone-one', 'platform' => 'ios']);
        UserDevice::where('user_id', $user->getKey())->update(['revoked_at' => now()]);

        // A device taken out of service should not hold a paid slot.
        $this->postJson('/api/v1/devices', ['device_uuid' => 'phone-two', 'platform' => 'ios'])
            ->assertStatus(201);
    }

    // ------------------------------------------------------ no company ------

    public function test_a_private_motorist_is_not_subject_to_company_limits(): void
    {
        // Vehicles registered by a user with no company are owned personally,
        // not by a tenant, so no plan applies.
        $this->actingAsRole('user', ['company_id' => null]);

        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/v1/vehicles', $this->vehiclePayload("PVT {$i}00"))->assertStatus(201);
        }
    }

    // ------------------------------------------------------------- service ---

    public function test_describe_reports_usage_against_limits(): void
    {
        Vehicle::factory()->count(3)->forCompany($this->company->id)->create();

        $report = app(SubscriptionLimitService::class)->describe($this->company->id, 'free');

        $this->assertSame('free', $report['tier']);
        $this->assertTrue($report['is_provisional'], 'The plan is not business-approved yet.');
        $this->assertSame(3, $report['resources']['vehicles']['used']);
        $this->assertSame(2, $report['resources']['vehicles']['limit']);
        $this->assertSame(0, $report['resources']['vehicles']['remaining']);
        $this->assertTrue($report['resources']['vehicles']['over_limit']);
    }

    public function test_an_unlimited_resource_reports_no_limit_rather_than_zero(): void
    {
        $report = app(SubscriptionLimitService::class)->describe($this->company->id, 'enterprise');

        $this->assertNull($report['resources']['vehicles']['limit']);
        $this->assertNull($report['resources']['vehicles']['remaining']);
        $this->assertFalse($report['resources']['vehicles']['over_limit']);
    }
}
