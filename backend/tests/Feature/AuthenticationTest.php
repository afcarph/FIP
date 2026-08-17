<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\User\Models\User;
use App\Domain\User\Services\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatform();
    }

    public function test_a_visitor_can_register(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'first_name' => 'Ella',
            'last_name' => 'Santos',
            'email' => 'ella@example.com',
            'password' => 'Str0ng!Passw0rd#2026',
            'password_confirmation' => 'Str0ng!Passw0rd#2026',
            'accepts_terms' => true,
        ]);

        $this->assertApiSuccess($response, 201);
        $response->assertJsonStructure(['data' => ['access_token', 'user' => ['id', 'email'], 'roles']]);

        $this->assertDatabaseHas('users', ['email' => 'ella@example.com']);
        $this->assertTrue(User::where('email', 'ella@example.com')->first()->hasRole('user'));
    }

    public function test_registration_rejects_a_weak_password(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'first_name' => 'Ella',
            'last_name' => 'Santos',
            'email' => 'ella@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'accepts_terms' => true,
        ]);

        $this->assertApiError($response, 'validation_failed', 422);
        $response->assertJsonPath('error.details.password.0', fn ($message) => $message !== null);
    }

    public function test_a_user_can_sign_in(): void
    {
        User::factory()->create([
            'email' => 'user@example.com',
            'password' => Hash::make('Str0ng!Passw0rd#2026'),
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'user@example.com',
            'password' => 'Str0ng!Passw0rd#2026',
        ]);

        $this->assertApiSuccess($response);
        $response->assertJsonStructure(['data' => ['access_token', 'expires_in', 'user']]);
    }

    public function test_a_browser_registration_is_named_readably(): void
    {
        // The owner's device list is where somebody revokes a session they do
        // not recognise. It used to show the raw User-Agent, which answers
        // nothing.
        User::factory()->create([
            'email' => 'browser@example.com',
            'password' => Hash::make('Str0ng!Passw0rd#2026'),
        ]);

        $this->withHeader(
            'User-Agent',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0 Safari/537.36',
        )->postJson('/api/v1/auth/login', [
            'email' => 'browser@example.com',
            'password' => 'Str0ng!Passw0rd#2026',
            'device' => ['device_uuid' => 'a-browser', 'platform' => 'web'],
        ])->assertStatus(200);

        $this->assertDatabaseHas('user_devices', [
            'device_uuid' => 'a-browser',
            'device_name' => 'Chrome on macOS',
        ]);
    }

    public function test_a_client_cannot_store_its_own_name_for_a_browser(): void
    {
        // The header is the server's, the payload is the caller's. Trusting
        // the payload would let a client write anything into a list a person
        // makes security decisions from.
        User::factory()->create([
            'email' => 'liar@example.com',
            'password' => Hash::make('Str0ng!Passw0rd#2026'),
        ]);

        $this->withHeader('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Firefox/130.0')
            ->postJson('/api/v1/auth/login', [
                'email' => 'liar@example.com',
                'password' => 'Str0ng!Passw0rd#2026',
                'device' => [
                    'device_uuid' => 'claims-otherwise',
                    'platform' => 'web',
                    'device_name' => "Ramon's iPhone",
                ],
            ])->assertStatus(200);

        $this->assertDatabaseHas('user_devices', [
            'device_uuid' => 'claims-otherwise',
            'device_name' => 'Firefox on Windows',
        ]);
        $this->assertDatabaseMissing('user_devices', ['device_name' => "Ramon's iPhone"]);
    }

    public function test_a_handset_still_names_itself(): void
    {
        // A phone knows what it is called and that name means something to the
        // person holding it. Only browsers are relabelled.
        User::factory()->create([
            'email' => 'driver@example.com',
            'password' => Hash::make('Str0ng!Passw0rd#2026'),
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'driver@example.com',
            'password' => 'Str0ng!Passw0rd#2026',
            'device' => ['device_uuid' => 'a-handset', 'platform' => 'ios', 'device_name' => "Ramon's iPhone"],
        ])->assertStatus(200);

        $this->assertDatabaseHas('user_devices', [
            'device_uuid' => 'a-handset',
            'device_name' => "Ramon's iPhone",
        ]);
    }

    public function test_an_unknown_email_and_a_wrong_password_are_indistinguishable(): void
    {
        User::factory()->create(['email' => 'known@example.com', 'password' => Hash::make('Str0ng!Passw0rd#2026')]);

        $unknown = $this->postJson('/api/v1/auth/login', ['email' => 'nobody@example.com', 'password' => 'whatever123!']);
        $wrong = $this->postJson('/api/v1/auth/login', ['email' => 'known@example.com', 'password' => 'whatever123!']);

        // Identical status and body: the endpoint must not enumerate accounts.
        $this->assertApiError($unknown, 'invalid_credentials', 401);
        $this->assertApiError($wrong, 'invalid_credentials', 401);
        $this->assertSame($unknown->json('error.message'), $wrong->json('error.message'));
    }

    public function test_repeated_failures_lock_the_account(): void
    {
        $user = User::factory()->create(['email' => 'target@example.com', 'password' => Hash::make('Str0ng!Passw0rd#2026')]);
        $max = (int) config('fip.security.max_failed_logins');

        for ($i = 0; $i < $max; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => 'target@example.com', 'password' => 'wrong-password-1!']);
        }

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'target@example.com',
            'password' => 'Str0ng!Passw0rd#2026',   // now correct, but too late
        ]);

        $this->assertApiError($response, 'account_locked', 423);
        $this->assertNotNull($user->fresh()->locked_until);
    }

    public function test_an_mfa_user_receives_a_challenge_rather_than_a_token(): void
    {
        User::factory()->create([
            'email' => 'mfa@example.com',
            'password' => Hash::make('Str0ng!Passw0rd#2026'),
            'mfa_enabled' => true,
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'mfa@example.com',
            'password' => 'Str0ng!Passw0rd#2026',
        ]);

        $this->assertApiSuccess($response);
        $response->assertJsonPath('data.status', 'mfa_required');
        $response->assertJsonMissingPath('data.access_token');
    }

    public function test_a_suspended_account_cannot_sign_in(): void
    {
        User::factory()->create([
            'email' => 'suspended@example.com',
            'password' => Hash::make('Str0ng!Passw0rd#2026'),
            'status' => 'suspended',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'suspended@example.com',
            'password' => 'Str0ng!Passw0rd#2026',
        ]);

        $this->assertApiError($response, 'account_inactive', 403);
    }

    public function test_protected_endpoints_reject_anonymous_callers(): void
    {
        $this->assertApiError($this->getJson('/api/v1/dashboard'), 'unauthenticated', 401);
        $this->assertApiError($this->getJson('/api/v1/vehicles'), 'unauthenticated', 401);
    }

    public function test_the_forgot_password_endpoint_does_not_reveal_registration(): void
    {
        User::factory()->create(['email' => 'known@example.com']);

        $known = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'known@example.com']);
        $unknown = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@example.com']);

        $known->assertOk();
        $unknown->assertOk();
        $this->assertSame($known->json('message'), $unknown->json('message'));
    }

    /**
     * `JWT::invalidate()` takes a `forceForever` flag, not a token. Passing the
     * token made the argument truthy, which sent every sign-out down
     * Blacklist::addForever() — a cache key with no expiry. The blacklist then
     * grew by one permanent entry per logout and never shed any of them.
     */
    public function test_logout_blacklists_the_token_without_forcing_it_forever(): void
    {
        $user = User::factory()->create();

        JWTAuth::shouldReceive('getToken')->once()->andReturn('header.payload.signature');
        // withNoArgs() is the assertion: any argument here means forceForever.
        JWTAuth::shouldReceive('invalidate')->once()->withNoArgs();

        app(AuthService::class)->logout($user);
    }

    public function test_logout_is_tolerant_of_a_token_that_cannot_be_parsed(): void
    {
        $user = User::factory()->create();

        JWTAuth::shouldReceive('getToken')->once()->andReturnNull();
        JWTAuth::shouldReceive('invalidate')->never();

        app(AuthService::class)->logout($user);

        $this->assertTrue(true, 'Signing out without a readable token must not raise.');
    }
}
