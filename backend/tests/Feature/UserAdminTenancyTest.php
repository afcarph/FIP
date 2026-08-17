<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\User\Models\Company;
use App\Domain\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tenancy on the write side of user administration.
 *
 * The read side was fixed separately (UserListingTenancyTest). This is its
 * twin: company_manager genuinely holds users.create and users.update, and
 * neither the controller nor the policy asked which company the target
 * belonged to. A tenant admin could therefore create a user inside somebody
 * else's company, edit anyone on the platform, and move a person between
 * tenants — and, because seats are charged to the target company, spend a
 * competitor's subscription capacity doing it.
 *
 * Platform administrators keep all of that on purpose. Operating across
 * tenants is their job; it is only a defect when a tenant can do it.
 */
class UserAdminTenancyTest extends TestCase
{
    use RefreshDatabase;

    private Company $acme;

    private Company $rival;

    private User $theirStaff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatform();

        $this->acme = Company::factory()->create(['name' => 'Acme Haulage']);
        $this->rival = Company::factory()->create(['name' => 'Rival Freight']);

        $this->theirStaff = User::factory()->create([
            'company_id' => $this->rival->id,
            'email' => 'staff@rival.test',
        ]);
    }

    /** @return array<string, mixed> */
    private function newUser(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'New',
            'last_name' => 'Starter',
            'email' => 'new.starter@example.test',
            'password' => 'S3cure-Passphrase!x9',
            'roles' => ['driver'],
        ], $overrides);
    }

    // -------------------------------------------------------------- create ---

    public function test_a_company_manager_creates_inside_their_own_company(): void
    {
        $this->actingAsRole('company_manager', ['company_id' => $this->acme->id]);

        $this->postJson('/api/v1/admin/users', $this->newUser())->assertStatus(201);

        $this->assertSame(
            $this->acme->id,
            User::where('email', 'new.starter@example.test')->value('company_id'),
        );
    }

    public function test_a_company_manager_cannot_create_inside_another_company(): void
    {
        // The seat is charged to the named company, so without this a tenant
        // admin could also burn a competitor's subscription capacity.
        $this->actingAsRole('company_manager', ['company_id' => $this->acme->id]);

        $this->postJson('/api/v1/admin/users', $this->newUser(['company_id' => $this->rival->id]))
            ->assertStatus(403);

        $this->assertDatabaseMissing('users', ['email' => 'new.starter@example.test']);
    }

    public function test_naming_your_own_company_explicitly_is_fine(): void
    {
        // Rejection must key on "not yours", not on "you sent the field".
        $this->actingAsRole('company_manager', ['company_id' => $this->acme->id]);

        $this->postJson('/api/v1/admin/users', $this->newUser(['company_id' => $this->acme->id]))
            ->assertStatus(201);
    }

    public function test_a_platform_administrator_may_create_in_any_company(): void
    {
        $this->actingAsRole('super_admin');

        $this->postJson('/api/v1/admin/users', $this->newUser(['company_id' => $this->rival->id]))
            ->assertStatus(201);

        $this->assertSame(
            $this->rival->id,
            User::where('email', 'new.starter@example.test')->value('company_id'),
        );
    }

    // -------------------------------------------------------------- update ---

    public function test_a_company_manager_cannot_edit_another_companys_user(): void
    {
        $this->actingAsRole('company_manager', ['company_id' => $this->acme->id]);

        $this->patchJson("/api/v1/admin/users/{$this->theirStaff->getKey()}", ['first_name' => 'Hijacked'])
            ->assertStatus(403);

        $this->assertSame('staff@rival.test', $this->theirStaff->refresh()->email);
        $this->assertNotSame('Hijacked', $this->theirStaff->first_name);
    }

    public function test_a_company_manager_may_edit_their_own_companys_user(): void
    {
        $mine = User::factory()->create(['company_id' => $this->acme->id]);
        $this->actingAsRole('company_manager', ['company_id' => $this->acme->id]);

        $this->patchJson("/api/v1/admin/users/{$mine->getKey()}", ['first_name' => 'Renamed'])
            ->assertStatus(200);

        $this->assertSame('Renamed', $mine->refresh()->first_name);
    }

    public function test_a_company_manager_cannot_move_a_user_into_another_company(): void
    {
        // Moving a person between tenants is a platform action. Allowing it
        // would let a tenant admin hand their own staff to another company, or
        // quietly annex somebody by editing a field.
        $mine = User::factory()->create(['company_id' => $this->acme->id]);
        $this->actingAsRole('company_manager', ['company_id' => $this->acme->id]);

        $this->patchJson("/api/v1/admin/users/{$mine->getKey()}", ['company_id' => $this->rival->id])
            ->assertStatus(403);

        $this->assertSame($this->acme->id, $mine->refresh()->company_id);
    }

    public function test_a_platform_administrator_may_move_a_user_between_companies(): void
    {
        $this->actingAsRole('super_admin');

        $this->patchJson("/api/v1/admin/users/{$this->theirStaff->getKey()}", ['company_id' => $this->acme->id])
            ->assertStatus(200);

        $this->assertSame($this->acme->id, $this->theirStaff->refresh()->company_id);
    }

    // -------------------------------------------------------------- delete ---

    public function test_a_company_manager_cannot_delete_another_companys_user(): void
    {
        // company_manager does not hold users.delete today, so this asserts the
        // tenancy boundary rather than the permission — if the permission were
        // ever granted, the boundary must still hold.
        $this->actingAsRole('company_manager', ['company_id' => $this->acme->id]);

        $this->deleteJson("/api/v1/admin/users/{$this->theirStaff->getKey()}")->assertStatus(403);

        $this->assertNotSame('suspended', $this->theirStaff->refresh()->status);
    }

    // ------------------------------------------------------- companyless ---

    public function test_a_manager_with_no_company_cannot_create_into_one(): void
    {
        // Fail closed: no company means no tenant to create into, so falling
        // back to "any company" would be the original bug by another route.
        $this->actingAsRole('company_manager', ['company_id' => null]);

        $this->postJson('/api/v1/admin/users', $this->newUser(['company_id' => $this->rival->id]))
            ->assertStatus(403);
    }
}
