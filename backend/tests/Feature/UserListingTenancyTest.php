<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\User\Models\Company;
use App\Domain\User\Models\User;
use App\Domain\User\Repositories\UserRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Who the admin user listing may show you.
 *
 * Regression cover for a live leak: `users.view` is granted to company_manager,
 * the listing applied `company_id` as a *filter* rather than a boundary, and
 * User was the one company-owning model that had never taken HasCompanyScope.
 * A company manager therefore read every user on the platform — names, emails,
 * company and roles.
 *
 * A filter narrows what you may already see. It is not what decides what you
 * may see, and the difference is the whole of this file.
 */
class UserListingTenancyTest extends TestCase
{
    use RefreshDatabase;

    private Company $acme;

    private Company $rival;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatform();

        $this->acme = Company::factory()->create(['name' => 'Acme Haulage']);
        $this->rival = Company::factory()->create(['name' => 'Rival Freight']);

        User::factory()->create(['company_id' => $this->acme->id, 'email' => 'colleague@acme.test']);
        User::factory()->create(['company_id' => $this->rival->id, 'email' => 'stranger@rival.test']);
    }

    /** @return list<string> */
    private function listedEmails(): array
    {
        $response = $this->getJson('/api/v1/admin/users?per_page=100');
        $response->assertStatus(200);

        return array_column($response->json('data'), 'email');
    }

    public function test_a_company_manager_sees_only_their_own_tenant(): void
    {
        $manager = $this->actingAsRole('company_manager', ['company_id' => $this->acme->id]);

        $emails = $this->listedEmails();

        $this->assertContains('colleague@acme.test', $emails);
        $this->assertContains($manager->email, $emails);
        $this->assertNotContains('stranger@rival.test', $emails);
    }

    public function test_another_tenant_cannot_be_reached_by_asking_for_it(): void
    {
        // The filter is the attack: naming someone else's company_id must
        // narrow within your own tenant, never widen past it.
        $this->actingAsRole('company_manager', ['company_id' => $this->acme->id]);

        $response = $this->getJson("/api/v1/admin/users?company_id={$this->rival->id}&per_page=100");

        $response->assertStatus(200);
        $this->assertNotContains('stranger@rival.test', array_column($response->json('data'), 'email'));
    }

    public function test_a_platform_administrator_still_sees_everyone(): void
    {
        // The scope must not break the job it exists to permit.
        $this->actingAsRole('super_admin');

        $emails = $this->listedEmails();

        $this->assertContains('colleague@acme.test', $emails);
        $this->assertContains('stranger@rival.test', $emails);
    }

    public function test_a_user_with_no_company_sees_only_themselves(): void
    {
        // A private motorist is not a tenant. Showing them nothing would be
        // safe but would hide them from themselves; showing them everyone
        // would be the original bug by another route.
        $loner = $this->actingAsRole('company_manager', ['company_id' => null]);

        $emails = $this->listedEmails();

        $this->assertSame([$loner->email], $emails);
    }

    public function test_the_role_filter_actually_filters(): void
    {
        // It used to be applied to a query that was then discarded, so asking
        // for one role returned everybody — a filter that silently lies is
        // worse than no filter, because it is believed.
        $driver = User::factory()->create(['company_id' => $this->acme->id, 'email' => 'driver@acme.test']);
        $driver->assignRole('driver');

        $this->actingAsRole('company_manager', ['company_id' => $this->acme->id]);

        $response = $this->getJson('/api/v1/admin/users?role=driver&per_page=100');

        $response->assertStatus(200);
        $this->assertSame(['driver@acme.test'], array_column($response->json('data'), 'email'));
    }

    public function test_the_role_filter_cannot_escape_the_tenant(): void
    {
        // Combining the two: a filter applied after a boundary stays inside it.
        $theirDriver = User::factory()->create(['company_id' => $this->rival->id, 'email' => 'driver@rival.test']);
        $theirDriver->assignRole('driver');

        $this->actingAsRole('company_manager', ['company_id' => $this->acme->id]);

        $response = $this->getJson('/api/v1/admin/users?role=driver&per_page=100');

        $response->assertStatus(200);
        $this->assertNotContains('driver@rival.test', array_column($response->json('data'), 'email'));
    }

    public function test_authentication_is_unaffected_by_the_scope(): void
    {
        // The scope is opt-in (a `forUser` method, not a global scope). If it
        // ever became global, every login would start filtering by tenant and
        // nobody outside the acting company could sign in at all. This asserts
        // the unscoped lookup that authentication depends on still sees across
        // companies.
        $repository = app(UserRepository::class);

        $this->assertNotNull($repository->findByEmail('stranger@rival.test'));
        $this->assertNotNull($repository->findByEmail('colleague@acme.test'));
    }
}
