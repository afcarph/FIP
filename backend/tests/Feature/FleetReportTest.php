<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Reporting\Models\ReportDefinition;
use App\Domain\Reporting\Models\ReportRun;
use App\Domain\User\Models\Company;
use App\Domain\User\Models\User;
use App\Domain\Vehicle\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Tymon\JWTAuth\JWT;

/**
 * Generating a fleet report and getting the file back.
 *
 * The download route is the part worth covering closely. It replaced a signed
 * storage URL, which failed for two separate reasons: object storage signs
 * against the endpoint the *server* uses — an internal hostname no browser can
 * resolve — and a signed URL stops being subject to authorization the moment
 * it is issued. Streaming through the app fixes both, so the ownership,
 * expiry and completeness checks are what these tests pin down.
 */
class FleetReportTest extends TestCase
{
    use RefreshDatabase;

    private Company $acme;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatform();
        Storage::fake();

        $this->acme = Company::factory()->create();
        $this->manager = $this->actingAsRole('fleet_manager', ['company_id' => $this->acme->id]);

        Vehicle::factory()->forCompany($this->acme->id)->count(2)->create();
    }

    private function generate(array $overrides = [])
    {
        return $this->postJson('/api/v1/reports/generate', array_merge([
            'code' => 'fleet_utilisation',
            'format' => 'csv',
            'period' => 'monthly',
        ], $overrides));
    }

    // ------------------------------------------------------------ generate ---

    public function test_a_fleet_manager_generates_a_utilisation_report(): void
    {
        // Two vehicles is far under the sync ceiling, so this renders inline
        // and comes back already complete rather than queued.
        $response = $this->generate()->assertStatus(201);

        $response->assertJsonPath('data.status', ReportRun::STATUS_COMPLETED)
            ->assertJsonPath('data.code', 'fleet_utilisation')
            ->assertJsonPath('data.row_count', 2);
    }

    public function test_the_download_url_points_at_the_api_not_the_object_store(): void
    {
        /*
         * The regression this route exists for. The payload used to carry a
         * signed storage URL built from the internal endpoint — a link that
         * looked valid and was unreachable from any browser.
         */
        $url = $this->generate()->assertStatus(201)->json('data.download_url');

        $this->assertStringContainsString('/api/v1/reports/runs/', $url);
        $this->assertStringEndsWith('/download', $url);
    }

    public function test_a_report_the_caller_lacks_permission_for_is_refused(): void
    {
        $this->actingAsRole('driver', ['company_id' => $this->acme->id]);

        $this->generate()->assertStatus(403);
    }

    public function test_the_definition_list_hides_reports_the_caller_cannot_run(): void
    {
        $this->actingAsRole('driver', ['company_id' => $this->acme->id]);

        $codes = collect($this->getJson('/api/v1/reports/definitions')->assertStatus(200)->json('data'))
            ->pluck('code');

        $this->assertNotContains('fleet_utilisation', $codes);
    }

    public function test_an_unknown_format_is_refused(): void
    {
        $this->generate(['format' => 'docx'])->assertStatus(422);
    }

    // ------------------------------------------------------------ download ---

    public function test_the_generated_file_downloads(): void
    {
        $id = $this->generate()->json('data.id');

        $response = $this->get("/api/v1/reports/runs/{$id}/download")->assertStatus(200);

        // Named for the report and its period, not the storage key, so the file
        // means something in a downloads folder.
        $response->assertDownload('fleet_utilisation-'
            .now()->startOfMonth()->toDateString().'-to-'
            .now()->endOfMonth()->toDateString().'.csv');
    }

    public function test_the_csv_carries_the_vehicle_rows(): void
    {
        $plate = Vehicle::where('company_id', $this->acme->id)->first()->plate_number;
        $id = $this->generate()->json('data.id');

        $body = $this->get("/api/v1/reports/runs/{$id}/download")->streamedContent();

        $this->assertStringContainsString($plate, $body);
    }

    public function test_another_user_may_not_download_someone_elses_report(): void
    {
        // Ownership is per-requester, not per-company: a report is a thing you
        // asked for. This is exactly what a signed URL could not enforce.
        $id = $this->generate()->json('data.id');

        $this->actingAsRole('fleet_manager', ['company_id' => $this->acme->id]);

        $this->get("/api/v1/reports/runs/{$id}/download")->assertStatus(403);
    }

    public function test_an_unauthenticated_download_is_refused(): void
    {
        $id = $this->generate()->json('data.id');

        // All three layers, for the reason actingAsRole documents: the default
        // header would re-authenticate, the auth manager holds the resolved
        // guard, and the JWT singleton holds the token it already parsed.
        $this->withoutHeader('Authorization');
        $this->app['auth']->forgetGuards();
        $this->app->make(JWT::class)->unsetToken();

        $this->getJson("/api/v1/reports/runs/{$id}/download")->assertStatus(401);
    }

    public function test_an_expired_report_is_not_served(): void
    {
        // Retention is a promise about how long a file is kept. An expired run
        // must not serve a file that has merely not been swept up yet.
        $id = $this->generate()->json('data.id');
        ReportRun::find($id)->forceFill(['expires_at' => now()->subDay()])->save();

        // 410 Gone, and the message survives. abort(404) here rendered as "The
        // requested endpoint does not exist", which told the operator their URL
        // was wrong rather than that the report had expired.
        $this->getJson("/api/v1/reports/runs/{$id}/download")
            ->assertStatus(410)
            ->assertJsonPath('error.code', 'report_expired')
            ->assertJsonPath('error.message', 'That report has expired. Generate it again.');
    }

    public function test_an_unfinished_report_is_not_served(): void
    {
        $run = ReportRun::create([
            'report_definition_id' => ReportDefinition::where('code', 'fleet_utilisation')->first()->getKey(),
            'requested_by' => $this->manager->getKey(),
            'company_id' => $this->acme->id,
            'format' => 'csv',
            'status' => ReportRun::STATUS_QUEUED,
        ]);

        $this->getJson("/api/v1/reports/runs/{$run->getKey()}/download")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'report_not_ready');
    }

    public function test_a_missing_file_is_reported_rather_than_streamed(): void
    {
        $id = $this->generate()->json('data.id');
        Storage::delete(ReportRun::find($id)->file_path);

        $this->getJson("/api/v1/reports/runs/{$id}/download")
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'report_file_missing')
            ->assertJsonPath('error.message', 'The generated file is no longer available.');
    }

    // ---------------------------------------------------------------- runs ---

    public function test_the_history_returns_the_same_shape_as_generating(): void
    {
        /*
         * The list used to return the model as it sits in the database, while
         * generate() and show() returned a presented shape — different key
         * names for the same run. The page reads both, so it crashed on
         * `period.from` reading a row that only had `period_start`.
         */
        $generated = $this->generate()->assertStatus(201)->json('data');
        $listed = $this->getJson('/api/v1/reports/runs')->assertStatus(200)->json('data.0');

        $this->assertSame(array_keys($generated), array_keys($listed));
    }

    public function test_the_history_does_not_expose_the_storage_key(): void
    {
        // `file_path` is an internal object-storage key. It is not the caller's
        // business, and it is not reachable from a browser regardless.
        $this->generate()->assertStatus(201);

        $this->getJson('/api/v1/reports/runs')
            ->assertStatus(200)
            ->assertJsonMissingPath('data.0.file_path')
            ->assertJsonMissingPath('data.0.requested_by');
    }

    public function test_the_history_lists_only_your_own_runs(): void
    {
        $this->generate()->assertStatus(201);

        $this->actingAsRole('fleet_manager', ['company_id' => $this->acme->id]);
        $this->generate()->assertStatus(201);

        $this->getJson('/api/v1/reports/runs')->assertStatus(200)->assertJsonCount(1, 'data');
    }
}
