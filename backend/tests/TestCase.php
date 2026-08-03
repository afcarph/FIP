<?php

declare(strict_types=1);

namespace Tests;

use App\Domain\User\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tymon\JWTAuth\JWT;

abstract class TestCase extends BaseTestCase
{
    /** Seed the authorisation matrix and reference data once per test. */
    protected function seedPlatform(): void
    {
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(RolePermissionSeeder::class);
    }

    /** Authenticate as a user with the given role and return them. */
    protected function actingAsRole(string $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->assignRole($role);

        // Both layers cache the previous request's identity within a single
        // test: the auth manager holds the resolved guard, and the JWT instance
        // holds the token it already parsed. Clear both, or a test that switches
        // identity mid-way silently keeps acting as the first user.
        //
        // Note the guard is built with the `tymon.jwt` singleton (JWT::class),
        // which is *not* the one behind the JWTAuth facade (`tymon.jwt.auth`) —
        // clearing the facade's copy would leave the guard's token in place.
        $this->app['auth']->forgetGuards();
        $this->app->make(JWT::class)->unsetToken();

        $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($user));

        return $user;
    }

    /** Assert the standard success envelope. */
    protected function assertApiSuccess($response, int $status = 200): void
    {
        $response->assertStatus($status)->assertJson(['success' => true]);
    }

    /**
     * Assert a 422 carries validation messages for the given fields.
     *
     * Laravel's own assertJsonValidationErrors() looks for a top-level `errors`
     * key; this API nests them under `error.details` (see
     * docs/05-api-documentation.md), so the envelope needs its own assertion.
     */
    protected function assertApiValidationErrors($response, string ...$fields): void
    {
        $this->assertApiError($response, 'validation_failed', 422);

        $details = $response->json('error.details') ?? [];

        foreach ($fields as $field) {
            $this->assertArrayHasKey(
                $field,
                $details,
                sprintf('Expected a validation message for [%s]; got [%s].', $field, implode(', ', array_keys($details))),
            );
        }
    }

    /** Assert the standard error envelope carries a specific code. */
    protected function assertApiError($response, string $code, int $status): void
    {
        $response->assertStatus($status)->assertJson([
            'success' => false,
            'error' => ['code' => $code],
        ]);
    }
}
