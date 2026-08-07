<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Doe\Models\FuelReport;
use App\Domain\Doe\Models\ImportRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Readiness, and the operator dashboard behind it.
 *
 * The thing worth testing here is not that the endpoint returns 200 — it is
 * that it returns something other than 200 when it should. A health check that
 * cannot fail is a health check that tells you nothing.
 */
class HealthEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function importRun(array $overrides = []): ImportRun
    {
        return ImportRun::create(array_merge([
            'started_at' => now()->subMinutes(10),
            'finished_at' => now()->subMinutes(9),
            'duration_seconds' => 62.0,
            'pdfs_discovered' => 9,
            'pdfs_downloaded' => 1,
            'reports_discovered' => 3,
            'reports_imported' => 1,
            'reports_skipped' => 6,
            'reports_rejected' => 2,
            'records_imported' => 770,
            'records_updated' => 0,
            'status' => ImportRun::STATUS_SUCCESS,
            'run_id' => 'abc123',
        ], $overrides));
    }

    public function test_readiness_reports_every_check(): void
    {
        $this->importRun();

        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJsonPath('data.status', 'ok')
            ->assertJsonStructure([
                'data' => [
                    'status',
                    'checks' => [
                        'database' => ['status', 'latency_ms', 'driver'],
                        'scheduler' => ['status', 'last_run_at', 'hours_since_last_run'],
                        'disk' => ['status'],
                        'storage' => ['status', 'path', 'files', 'bytes'],
                    ],
                    'time',
                ],
            ]);
    }

    public function test_a_scheduler_that_has_not_fired_takes_the_instance_out_of_rotation(): void
    {
        // The failure this exists to catch. Every other signal stays green
        // when the scheduler dies: the last run succeeded, the data is valid,
        // the API serves it. Only its age says anything is wrong.
        $this->importRun(['started_at' => now()->subDays(3), 'finished_at' => now()->subDays(3)]);

        $this->getJson('/api/v1/health')
            ->assertStatus(503)
            ->assertJsonPath('data.status', 'down')
            ->assertJsonPath('data.checks.scheduler.status', 'down');
    }

    public function test_a_platform_that_has_never_run_the_ingest_is_not_ready(): void
    {
        $this->getJson('/api/v1/health')
            ->assertStatus(503)
            ->assertJsonPath('data.checks.scheduler.status', 'down');
    }

    public function test_a_run_within_the_window_is_healthy_even_when_it_imported_nothing(): void
    {
        // A week the DOE published nothing in is a healthy week. Reading it as
        // failure is what would page someone every morning between
        // publications.
        $this->importRun([
            'status' => ImportRun::STATUS_NO_CHANGES,
            'reports_imported' => 0,
            'records_imported' => 0,
        ]);

        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJsonPath('data.checks.scheduler.status', 'ok');
    }

    public function test_the_liveness_probe_stays_shallow(): void
    {
        // Deliberately answers without consulting the database, so a database
        // blip does not get healthy containers restarted.
        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok');
    }

    public function test_the_system_dashboard_requires_authentication(): void
    {
        // It reports filesystem paths, disk capacity and the database driver.
        $this->getJson('/api/v1/admin/system')->assertUnauthorized();
    }
}
