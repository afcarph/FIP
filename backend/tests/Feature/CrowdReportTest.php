<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Pricing\Models\FuelType;
use App\Domain\Pricing\Models\PriceReport;
use App\Domain\Station\Models\GasStation;
use App\Domain\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The crowd-sourcing gates are the platform's data-quality backbone: a bad
 * report that slips through corrupts prices for every user in the city.
 */
class CrowdReportTest extends TestCase
{
    use RefreshDatabase;

    private GasStation $station;

    private FuelType $fuelType;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatform();
        Queue::fake();

        $this->station = GasStation::factory()->create(['latitude' => 14.5547, 'longitude' => 121.0244]);
        $this->fuelType = FuelType::where('code', 'diesel')->firstOrFail();
    }

    public function test_a_user_can_submit_a_price_report_from_the_forecourt(): void
    {
        $this->actingAsRole('user');

        $response = $this->postJson('/api/v1/reports', [
            'station_id' => $this->station->id,
            'report_type' => 'price',
            'fuel_type_id' => $this->fuelType->id,
            'price' => 57.50,
            'latitude' => 14.5548,      // ~11 m away
            'longitude' => 121.0245,
        ]);

        $this->assertApiSuccess($response, 201);
        $this->assertDatabaseHas('price_reports', [
            'station_id' => $this->station->id,
            'price' => 57.5000,
        ]);
    }

    public function test_a_report_filed_from_far_away_is_rejected(): void
    {
        $this->actingAsRole('user');

        $response = $this->postJson('/api/v1/reports', [
            'station_id' => $this->station->id,
            'report_type' => 'price',
            'fuel_type_id' => $this->fuelType->id,
            'price' => 57.50,
            'latitude' => 14.6760,      // Quezon City — kilometres away
            'longitude' => 121.0437,
        ]);

        $this->assertApiError($response, 'report_too_far', 422);
        $this->assertDatabaseCount('price_reports', 0);
    }

    public function test_a_second_report_within_the_hour_is_refused(): void
    {
        $user = $this->actingAsRole('user');

        PriceReport::factory()->create([
            'user_id' => $user->id,
            'station_id' => $this->station->id,
            'created_at' => now()->subMinutes(10),
        ]);

        $response = $this->postJson('/api/v1/reports', [
            'station_id' => $this->station->id,
            'report_type' => 'price',
            'fuel_type_id' => $this->fuelType->id,
            'price' => 57.50,
            'latitude' => 14.5548,
            'longitude' => 121.0245,
        ]);

        $this->assertApiError($response, 'duplicate_report', 429);
    }

    public function test_a_price_report_requires_a_fuel_type_and_a_figure(): void
    {
        $this->actingAsRole('user');

        $response = $this->postJson('/api/v1/reports', [
            'station_id' => $this->station->id,
            'report_type' => 'price',
        ]);

        $this->assertApiError($response, 'validation_failed', 422);
        $response->assertJsonValidationErrors(['fuel_type_id', 'price', 'latitude', 'longitude']);
    }

    public function test_an_operational_report_needs_no_price(): void
    {
        $this->actingAsRole('user');

        $response = $this->postJson('/api/v1/reports', [
            'station_id' => $this->station->id,
            'report_type' => 'long_queue',
            'comment' => 'About 15 vehicles deep.',
        ]);

        $this->assertApiSuccess($response, 201);
    }

    public function test_a_user_cannot_vote_on_their_own_report(): void
    {
        $user = $this->actingAsRole('user');

        $report = PriceReport::factory()->create(['user_id' => $user->id, 'station_id' => $this->station->id]);

        $this->assertApiError(
            $this->postJson("/api/v1/reports/{$report->id}/vote", ['vote' => 1]),
            'self_vote',
            422,
        );
    }

    public function test_only_a_moderator_can_approve_a_report(): void
    {
        $report = PriceReport::factory()->create([
            'user_id' => User::factory()->create()->id,
            'station_id' => $this->station->id,
            'fuel_type_id' => $this->fuelType->id,
            'price' => 57.50,
            'status' => PriceReport::STATUS_PENDING,
        ]);

        $this->actingAsRole('user');
        $this->postJson("/api/v1/admin/moderation/reports/{$report->id}/approve")->assertForbidden();

        $this->actingAsRole('system_admin');
        $this->assertApiSuccess($this->postJson("/api/v1/admin/moderation/reports/{$report->id}/approve"));

        $this->assertSame(PriceReport::STATUS_APPROVED, $report->fresh()->status);
        $this->assertDatabaseHas('station_prices', [
            'station_id' => $this->station->id,
            'fuel_type_id' => $this->fuelType->id,
            'source' => 'crowd',
        ]);
    }
}
