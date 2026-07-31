<?php

declare(strict_types=1);

namespace Tests;

use App\Domain\User\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

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

        $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($user));

        return $user;
    }

    /** Assert the standard success envelope. */
    protected function assertApiSuccess($response, int $status = 200): void
    {
        $response->assertStatus($status)->assertJson(['success' => true]);
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
