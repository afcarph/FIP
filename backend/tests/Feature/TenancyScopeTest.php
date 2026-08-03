<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Fleet\Models\Fleet;
use App\Domain\User\Models\Company;
use App\Domain\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `forUser()` is the multi-tenancy guard rail, so its behaviour for a caller
 * who belongs to no company matters as much as for one who does.
 *
 * It used to fall back to a guessed `<table>.user_id`, which does not exist on
 * every model that uses the scope — fleets key on `manager_id` — so a personal
 * user hitting a fleet endpoint got a SQL error surfaced as a 500 rather than
 * an empty list.
 */
class TenancyScopeTest extends TestCase
{
    use RefreshDatabase;

    private function fleetFor(User $manager, Company $company): Fleet
    {
        return Fleet::create([
            'company_id' => $company->getKey(),
            'name' => 'Metro Manila Fleet',
            'code' => 'MM-1',
            'manager_id' => $manager->getKey(),
            'is_active' => true,
        ]);
    }

    public function test_a_user_without_a_company_sees_no_fleets_and_no_error(): void
    {
        $this->seedPlatform();

        $company = Company::factory()->create();
        $manager = User::factory()->create(['company_id' => $company->getKey()]);
        $this->fleetFor($manager, $company);

        $outsider = User::factory()->create(['company_id' => null]);

        $this->assertSame(0, Fleet::query()->forUser($outsider)->count());
    }

    public function test_a_manager_sees_their_own_company_fleets(): void
    {
        $this->seedPlatform();

        $company = Company::factory()->create();
        $manager = User::factory()->create(['company_id' => $company->getKey()]);
        $this->fleetFor($manager, $company);

        $this->assertSame(1, Fleet::query()->forUser($manager)->count());
    }

    public function test_one_company_cannot_see_another_companys_fleets(): void
    {
        $this->seedPlatform();

        $ours = Company::factory()->create();
        $theirs = Company::factory()->create();

        $ourManager = User::factory()->create(['company_id' => $ours->getKey()]);
        $theirManager = User::factory()->create(['company_id' => $theirs->getKey()]);

        $this->fleetFor($ourManager, $ours);
        $this->fleetFor($theirManager, $theirs);

        $visible = Fleet::query()->forUser($ourManager)->get();

        $this->assertCount(1, $visible);
        $this->assertSame($ours->getKey(), $visible->first()->company_id);
    }

    public function test_an_unauthenticated_caller_sees_nothing(): void
    {
        $this->seedPlatform();

        $company = Company::factory()->create();
        $manager = User::factory()->create(['company_id' => $company->getKey()]);
        $this->fleetFor($manager, $company);

        $this->assertSame(0, Fleet::query()->forUser(null)->count());
    }
}
