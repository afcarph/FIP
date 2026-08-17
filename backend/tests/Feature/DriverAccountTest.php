<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Fleet\Models\Driver;
use App\Domain\User\Models\Company;
use App\Domain\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Giving a driver a login.
 *
 * The capability exists because a fleet manager could hire a driver, assign
 * them a vehicle and read their licence, but not hand them a way into the app —
 * so the onboarding step asking them to do exactly that was unfinishable by the
 * role being asked.
 *
 * What these protect is that it stayed *narrow*. It makes one shape of account
 * and refuses everything else, and a caller cannot use it to reach a driver, a
 * company or a role that is not theirs.
 */
class DriverAccountTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Driver $driver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatform();

        $this->company = Company::factory()->create(['subscription_tier' => 'business']);
        $this->driver = Driver::create([
            'company_id' => $this->company->id,
            'first_name' => 'Pilot',
            'last_name' => 'Driver',
            'phone' => '+63 900 000 0000',
            'status' => 'active',
        ]);
    }

    private function createAccount(?int $driverId = null, array $payload = []): TestResponse
    {
        return $this->postJson(
            '/api/v1/fleet/drivers/'.($driverId ?? $this->driver->getKey()).'/account',
            $payload + ['email' => 'pilot@haulers.test'],
        );
    }

    // ------------------------------------------------------- the happy path ---

    public function test_a_fleet_manager_can_give_their_driver_a_login(): void
    {
        $this->actingAsRole('fleet_manager', ['company_id' => $this->company->id]);

        $response = $this->createAccount()->assertStatus(201);

        $user = User::firstWhere('email', 'pilot@haulers.test');

        $this->assertNotNull($user);
        $this->assertTrue($user->hasRole('driver'));
        $this->assertSame($this->company->id, $user->company_id);
        $this->assertSame($user->getKey(), $this->driver->refresh()->user_id);
        // The name comes from the driver record rather than being asked for
        // again, so one person cannot end up spelled two ways.
        $this->assertSame('Pilot', $user->first_name);
        $this->assertNotEmpty($response->json('data.temporary_password'));
    }

    public function test_the_temporary_password_actually_signs_in(): void
    {
        // A credential that is displayed but does not work is worse than none:
        // the manager has already read it out before anyone finds out.
        $this->actingAsRole('fleet_manager', ['company_id' => $this->company->id]);

        $password = $this->createAccount()->json('data.temporary_password');

        $this->postJson('/api/v1/auth/login', [
            'email' => 'pilot@haulers.test',
            'password' => $password,
        ])->assertStatus(200);
    }

    public function test_the_password_is_stored_only_as_a_hash(): void
    {
        $this->actingAsRole('fleet_manager', ['company_id' => $this->company->id]);

        $password = $this->createAccount()->json('data.temporary_password');
        $user = User::firstWhere('email', 'pilot@haulers.test');

        $this->assertNotSame($password, $user->password);
        $this->assertTrue(Hash::check($password, $user->password));
    }

    public function test_a_company_manager_can_do_it_too(): void
    {
        $this->actingAsRole('company_manager', ['company_id' => $this->company->id]);

        $this->createAccount()->assertStatus(201);
    }

    // ------------------------------------------------------------ refusals ---

    public function test_a_driver_cannot_mint_logins(): void
    {
        $this->actingAsRole('driver', ['company_id' => $this->company->id]);

        $this->createAccount()->assertStatus(403);
    }

    public function test_a_viewer_cannot_mint_logins(): void
    {
        // Read-only oversight stays read-only, and a credential is a write.
        $this->actingAsRole('viewer', ['company_id' => $this->company->id]);

        $this->createAccount()->assertStatus(403);
    }

    public function test_another_companys_driver_is_refused(): void
    {
        $stranger = Company::factory()->create();
        $theirDriver = Driver::create([
            'company_id' => $stranger->id,
            'first_name' => 'Not',
            'last_name' => 'Yours',
            'status' => 'active',
        ]);

        $this->actingAsRole('fleet_manager', ['company_id' => $this->company->id]);

        $this->createAccount($theirDriver->getKey())->assertStatus(403);
        $this->assertNull($theirDriver->refresh()->user_id);
    }

    public function test_a_driver_who_already_has_a_login_is_refused(): void
    {
        $existing = User::factory()->create(['company_id' => $this->company->id]);
        $this->driver->forceFill(['user_id' => $existing->getKey()])->save();

        $this->actingAsRole('fleet_manager', ['company_id' => $this->company->id]);

        $response = $this->createAccount()->assertStatus(409);

        $this->assertSame('driver_already_has_account', $response->json('error.code'));
    }

    public function test_a_taken_email_is_refused_without_creating_anything(): void
    {
        User::factory()->create(['email' => 'pilot@haulers.test']);

        $this->actingAsRole('fleet_manager', ['company_id' => $this->company->id]);

        $this->createAccount()->assertStatus(422);
        $this->assertNull($this->driver->refresh()->user_id);
    }

    public function test_it_cannot_be_used_to_choose_a_role_or_a_company(): void
    {
        // The reason this is not `users.create`. Anything smuggled into the
        // payload is ignored: the role is fixed and the company comes from the
        // driver.
        $stranger = Company::factory()->create();

        $this->actingAsRole('fleet_manager', ['company_id' => $this->company->id]);

        $this->createAccount(null, [
            'roles' => ['super_admin'],
            'company_id' => $stranger->id,
            'password' => 'chosen-by-the-caller',
        ])->assertStatus(201);

        $user = User::firstWhere('email', 'pilot@haulers.test');

        $this->assertSame(['driver'], $user->getRoleNames()->all());
        $this->assertSame($this->company->id, $user->company_id);
        $this->assertFalse(Hash::check('chosen-by-the-caller', $user->password));
    }

    public function test_a_login_spends_a_seat_and_is_refused_when_the_plan_is_full(): void
    {
        config(['fip.subscription.tiers.business.seats' => 1]);

        // The acting fleet manager is already the one seat.
        $this->actingAsRole('fleet_manager', ['company_id' => $this->company->id]);

        $response = $this->createAccount()->assertStatus(402);

        $this->assertSame('subscription_limit_reached', $response->json('error.code'));
        $this->assertNull($this->driver->refresh()->user_id);
    }

    public function test_creating_a_login_requires_authentication(): void
    {
        $this->createAccount()->assertStatus(401);
    }

    // -------------------------------------------------------- what is shown ---

    public function test_the_roster_says_whether_a_driver_can_sign_in(): void
    {
        // The question the whole flow turns on, and one no screen could answer
        // before: a driver record can exist for months with no login.
        $this->actingAsRole('fleet_manager', ['company_id' => $this->company->id]);

        $before = collect($this->getJson('/api/v1/fleet/drivers')->json('data'))
            ->firstWhere('id', $this->driver->getKey());

        $this->assertNull($before['account']);

        $this->createAccount()->assertStatus(201);

        $after = collect($this->getJson('/api/v1/fleet/drivers')->json('data'))
            ->firstWhere('id', $this->driver->getKey());

        $this->assertSame('pilot@haulers.test', $after['account']['email']);
    }
}
