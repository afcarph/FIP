<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Pricing\Models\FuelType;
use App\Domain\User\Models\Company;
use App\Domain\User\Models\User;
use App\Domain\User\Services\CompanyRegistrationService;
use App\Domain\User\Services\SubscriptionLimitService;
use App\Domain\Vehicle\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Registering a client.
 *
 * Three things have to be true of every registration, whatever plan is chosen:
 * the company, its subscription and its first administrator are created
 * together or not at all; the plan is what the *server* decided rather than
 * what the client asked for; and the new tenant is isolated from every other
 * one from its first request.
 */
class CompanyRegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatform();

        config([
            'fip.subscription.trial_days' => 14,
            'fip.subscription.default_tier' => 'free',
            'fip.subscription.pending_tier' => 'business',
            'fip.subscription.tiers' => [
                'free_trial' => ['vehicles' => 3, 'seats' => 2, 'devices' => 3],
                'free' => ['vehicles' => 3, 'seats' => 2, 'devices' => 3],
                'business' => ['vehicles' => 25, 'seats' => 15, 'devices' => 30],
                'enterprise' => ['vehicles' => null, 'seats' => null, 'devices' => null],
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(string $plan, string $email = 'admin@haulers.test'): array
    {
        return [
            'plan' => $plan,
            'company' => ['name' => 'Haulers Inc', 'type' => 'logistics'],
            'admin' => [
                'first_name' => 'Rosa',
                'last_name' => 'Lim',
                'email' => $email,
                'password' => 'Str0ng!Passw0rd#2026',
                'password_confirmation' => 'Str0ng!Passw0rd#2026',
            ],
            'accepts_terms' => true,
        ];
    }

    // ---------------------------------------------------------- the paths ---

    public function test_a_free_trial_registration_starts_a_dated_trial(): void
    {
        $response = $this->postJson('/api/v1/auth/register-company', $this->payload('free_trial'));

        $response->assertStatus(201);

        $company = Company::firstWhere('name', 'Haulers Inc');

        $this->assertSame('free_trial', $company->subscription_tier);
        $this->assertSame(Company::STATUS_TRIALING, $company->subscription_status);
        $this->assertNotNull($company->trial_started_at);
        // The length comes from configuration, not from a constant in the code.
        $this->assertSame(14, (int) $company->trial_started_at->diffInDays($company->trial_ends_at));
    }

    public function test_a_business_registration_is_active_immediately(): void
    {
        $this->postJson('/api/v1/auth/register-company', $this->payload('business'))->assertStatus(201);

        $company = Company::firstWhere('name', 'Haulers Inc');

        $this->assertSame('business', $company->subscription_tier);
        $this->assertSame(Company::STATUS_ACTIVE, $company->subscription_status);
        $this->assertNull($company->trial_ends_at);
    }

    public function test_an_enterprise_registration_waits_for_confirmation(): void
    {
        // Enterprise limits are negotiated. Handing them to whoever typed a
        // company name would make the negotiation meaningless, so the company
        // is created and works — on the default allowance — until an
        // administrator confirms what was agreed.
        $this->postJson('/api/v1/auth/register-company', $this->payload('enterprise'))->assertStatus(201);

        $company = Company::firstWhere('name', 'Haulers Inc');

        $this->assertSame('enterprise', $company->subscription_tier);
        $this->assertSame(Company::STATUS_PENDING_SETUP, $company->subscription_status);

        // The interim is the largest *bounded* plan, not the smallest one.
        // Falling back to the default meant a prospect who chose Enterprise
        // was offered three vehicles, which blocks a real evaluation on its
        // first afternoon and reads as an insult.
        $this->assertSame('business', $company->effectiveTier());
        $this->assertSame(25, app(SubscriptionLimitService::class)
            ->limitForCompany($company, SubscriptionLimitService::VEHICLES));
    }

    public function test_the_interim_allowance_is_never_the_negotiated_one(): void
    {
        // The security property the interim exists for: whatever it is set to,
        // an unconfirmed enterprise selection must not receive the unlimited
        // capacity that is still only an intention.
        $this->postJson('/api/v1/auth/register-company', $this->payload('enterprise'))->assertStatus(201);

        $company = Company::firstWhere('name', 'Haulers Inc');
        $limits = app(SubscriptionLimitService::class);

        foreach ([SubscriptionLimitService::VEHICLES, SubscriptionLimitService::SEATS, SubscriptionLimitService::DEVICES] as $resource) {
            $this->assertNotNull(
                $limits->limitForCompany($company, $resource),
                "an unconfirmed enterprise company was granted unlimited {$resource}",
            );
        }
    }

    // ------------------------------------------------------- what is built ---

    public function test_it_creates_the_company_the_subscription_and_one_administrator(): void
    {
        $this->postJson('/api/v1/auth/register-company', $this->payload('business'))->assertStatus(201);

        $company = Company::firstWhere('name', 'Haulers Inc');
        $admin = User::firstWhere('email', 'admin@haulers.test');

        $this->assertSame($company->getKey(), $admin->company_id);
        $this->assertTrue($admin->hasRole('company_manager'));
        $this->assertSame(1, User::where('company_id', $company->getKey())->count());
    }

    public function test_registration_never_creates_a_platform_administrator(): void
    {
        // super_admin belongs to whoever runs FIP, not to whoever registers.
        $this->postJson('/api/v1/auth/register-company', $this->payload('business'))->assertStatus(201);

        $admin = User::firstWhere('email', 'admin@haulers.test');

        $this->assertFalse($admin->hasRole('super_admin'));
        $this->assertFalse($admin->hasRole('system_admin'));
    }

    public function test_the_administrator_is_signed_in_and_lands_with_their_subscription(): void
    {
        $response = $this->postJson('/api/v1/auth/register-company', $this->payload('free_trial'));

        $response->assertStatus(201)
            ->assertJsonPath('data.subscription.tier', 'free_trial')
            ->assertJsonPath('data.subscription.status', Company::STATUS_TRIALING)
            ->assertJsonPath('data.subscription.resources.vehicles.limit', 3);

        $this->assertNotEmpty($response->json('data.access_token'));
    }

    public function test_nothing_is_created_when_the_administrator_cannot_be(): void
    {
        User::factory()->create(['email' => 'taken@haulers.test']);

        $before = Company::count();

        $this->postJson('/api/v1/auth/register-company', $this->payload('business', 'taken@haulers.test'))
            ->assertStatus(422);

        $this->assertSame($before, Company::count(), 'a company was left behind by a failed registration');
    }

    // -------------------------------------------------------- the plan sent ---

    public function test_a_plan_nobody_configured_is_refused(): void
    {
        $this->postJson('/api/v1/auth/register-company', $this->payload('unlimited_everything'))
            ->assertStatus(422);
    }

    public function test_limits_are_never_taken_from_the_request(): void
    {
        // The client says which plan it wants. The server decides what that
        // means, and a payload claiming its own limits changes nothing.
        $payload = $this->payload('free_trial') + ['limits' => ['vehicles' => 9999]];
        $payload['company']['subscription_limits'] = ['vehicles' => 9999];

        $this->postJson('/api/v1/auth/register-company', $payload)->assertStatus(201);

        $company = Company::firstWhere('name', 'Haulers Inc');

        $this->assertNull($company->subscription_limits);
        $this->assertSame(3, app(SubscriptionLimitService::class)
            ->limitForCompany($company, SubscriptionLimitService::VEHICLES));
    }

    // ---------------------------------------------------------- isolation ---

    public function test_a_new_company_cannot_see_another_ones_fleet(): void
    {
        $stranger = Company::factory()->create();
        Vehicle::factory()->count(3)->create(['company_id' => $stranger->getKey()]);

        $token = $this->postJson('/api/v1/auth/register-company', $this->payload('business'))
            ->json('data.access_token');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/vehicles')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }

    public function test_a_new_admin_cannot_read_another_companys_subscription(): void
    {
        $stranger = Company::factory()->create(['subscription_tier' => 'enterprise']);

        $token = $this->postJson('/api/v1/auth/register-company', $this->payload('free_trial'))
            ->json('data.access_token');

        // Their own, whatever they name.
        $body = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/fleet/subscription?company_id={$stranger->getKey()}")
            ->assertStatus(200)
            ->json('data');

        $this->assertSame('free_trial', $body['tier']);

        // And the platform view stays shut.
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/admin/companies/{$stranger->getKey()}")
            ->assertStatus(403);
    }

    // -------------------------------------------------------- enforcement ---

    public function test_the_trial_limit_is_enforced_against_the_new_company(): void
    {
        $token = $this->postJson('/api/v1/auth/register-company', $this->payload('free_trial'))
            ->json('data.access_token');

        $company = Company::firstWhere('name', 'Haulers Inc');
        Vehicle::factory()->count(3)->create(['company_id' => $company->getKey()]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/vehicles', [
                'plate_number' => 'AAA 1111',
                'vehicle_type' => 'van',
                'fuel_type_id' => FuelType::query()->value('id'),
                'tank_capacity' => 50,
            ]);

        $response->assertStatus(402);
        $this->assertSame('subscription_limit_reached', $response->json('error.code'));
    }

    // ------------------------------------------------------- transitions ---

    public function test_a_trial_converts_to_business(): void
    {
        $this->postJson('/api/v1/auth/register-company', $this->payload('free_trial'))->assertStatus(201);

        $company = Company::firstWhere('name', 'Haulers Inc');
        $started = $company->trial_started_at;

        app(CompanyRegistrationService::class)->changePlan($company, 'business');

        $company->refresh();

        $this->assertSame('business', $company->subscription_tier);
        $this->assertSame(Company::STATUS_ACTIVE, $company->subscription_status);
        // The trial is history, not erased: it happened, and the dates say when.
        $this->assertEquals($started->toDateTimeString(), $company->trial_started_at->toDateTimeString());
    }

    public function test_a_trial_converts_to_enterprise_and_waits_for_its_numbers(): void
    {
        $this->postJson('/api/v1/auth/register-company', $this->payload('free_trial'))->assertStatus(201);

        $company = Company::firstWhere('name', 'Haulers Inc');

        app(CompanyRegistrationService::class)->changePlan($company, 'enterprise');

        $this->assertSame(Company::STATUS_PENDING_SETUP, $company->refresh()->subscription_status);
    }

    public function test_confirming_an_enterprise_agreement_applies_the_negotiated_numbers(): void
    {
        $this->postJson('/api/v1/auth/register-company', $this->payload('enterprise'))->assertStatus(201);

        $company = Company::firstWhere('name', 'Haulers Inc');

        app(CompanyRegistrationService::class)
            ->changePlan($company, 'enterprise', ['vehicles' => 250, 'seats' => 80, 'devices' => null]);

        $company->refresh();
        $limits = app(SubscriptionLimitService::class);

        $this->assertSame(Company::STATUS_ACTIVE, $company->subscription_status);
        $this->assertSame(250, $limits->limitForCompany($company, SubscriptionLimitService::VEHICLES));
        // A negotiated null is a deliberate "no limit" and must survive.
        $this->assertNull($limits->limitForCompany($company, SubscriptionLimitService::DEVICES));
    }

    public function test_business_renewal_is_not_a_special_case(): void
    {
        $this->postJson('/api/v1/auth/register-company', $this->payload('business'))->assertStatus(201);

        $company = Company::firstWhere('name', 'Haulers Inc');

        app(CompanyRegistrationService::class)->changePlan($company, 'business');

        $this->assertSame(Company::STATUS_ACTIVE, $company->refresh()->subscription_status);
    }

    // -------------------------------------------------------- expiration ---

    public function test_an_expired_trial_refuses_new_records_and_keeps_the_old_ones(): void
    {
        config(['fip.subscription.trial_expiry' => 'block_creation']);

        $token = $this->postJson('/api/v1/auth/register-company', $this->payload('free_trial'))
            ->json('data.access_token');

        $company = Company::firstWhere('name', 'Haulers Inc');
        $vehicle = Vehicle::factory()->create(['company_id' => $company->getKey()]);

        $company->update(['trial_ends_at' => now()->subDay()]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/vehicles', [
                'plate_number' => 'BBB 2222',
                'vehicle_type' => 'van',
                'fuel_type_id' => FuelType::query()->value('id'),
                'tank_capacity' => 50,
            ]);

        $response->assertStatus(402);
        $this->assertSame('trial_expired', $response->json('error.code'));

        // Nothing is deleted, hidden or made read-only by a lapsed trial.
        $this->assertDatabaseHas('vehicles', ['id' => $vehicle->getKey()]);
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/vehicles')->assertStatus(200)->assertJsonCount(1, 'data');
    }

    public function test_an_expired_trial_can_be_configured_to_change_nothing(): void
    {
        // Whether a lapsed trial should bite at all is a business decision.
        config(['fip.subscription.trial_expiry' => 'none']);

        $token = $this->postJson('/api/v1/auth/register-company', $this->payload('free_trial'))
            ->json('data.access_token');

        Company::firstWhere('name', 'Haulers Inc')->update(['trial_ends_at' => now()->subDay()]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/vehicles', [
                'plate_number' => 'CCC 3333',
                'vehicle_type' => 'van',
                'fuel_type_id' => FuelType::query()->value('id'),
                'tank_capacity' => 50,
            ])->assertStatus(201);
    }

    // ------------------------------------------------------------- plans ---

    public function test_the_plans_endpoint_is_public_and_carries_no_pricing(): void
    {
        $body = $this->getJson('/api/v1/plans')->assertStatus(200)->json('data');

        $names = array_column($body['plans'], 'name');

        $this->assertContains('free_trial', $names);
        $this->assertContains('business', $names);
        $this->assertContains('enterprise', $names);
        $this->assertTrue($body['is_provisional']);
        $this->assertSame(14, $body['trial_days']);

        foreach ($body['plans'] as $plan) {
            $this->assertArrayNotHasKey('price', $plan);
            $this->assertArrayHasKey('limits', $plan);
        }
    }

    public function test_the_plans_endpoint_serves_the_backends_own_limits(): void
    {
        // One source of truth: a page rendering its own numbers would drift the
        // first time the business changed one.
        config(['fip.subscription.tiers.business.vehicles' => 41]);

        $body = $this->getJson('/api/v1/plans')->json('data');
        $business = collect($body['plans'])->firstWhere('name', 'business');

        $this->assertSame(41, $business['limits']['vehicles']);
    }
}
