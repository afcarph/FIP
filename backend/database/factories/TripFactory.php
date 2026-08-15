<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Expense\Models\Trip;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Trip>
 */
class TripFactory extends Factory
{
    protected $model = Trip::class;

    public function definition(): array
    {
        return [
            'status' => Trip::STATUS_DRAFT,
            'origin_label' => 'Manila',
            'destination_label' => 'Batangas',
            'purpose' => 'Delivery',
        ];
    }

    /**
     * A trip already sent out. Kept as a state rather than left to each test,
     * so the timestamps that go with a status are never half-written.
     */
    public function dispatched(): static
    {
        return $this->state(fn () => [
            'status' => Trip::STATUS_DISPATCHED,
            'dispatched_at' => now(),
        ]);
    }

    public function inProgress(): static
    {
        return $this->state(fn () => [
            'status' => Trip::STATUS_IN_PROGRESS,
            'dispatched_at' => now()->subHour(),
            'started_at' => now(),
            'odometer_start' => 10_000,
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => Trip::STATUS_COMPLETED,
            'dispatched_at' => now()->subHours(3),
            'started_at' => now()->subHours(2),
            'ended_at' => now(),
            'odometer_start' => 10_000,
            'odometer_end' => 10_120,
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => [
            'status' => Trip::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'cancellation_reason' => 'Customer postponed',
        ]);
    }
}
