<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Pricing\Models\FuelType;
use App\Domain\Pricing\Models\PriceReport;
use App\Domain\Station\Models\GasStation;
use App\Domain\User\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PriceReport> */
class PriceReportFactory extends Factory
{
    protected $model = PriceReport::class;

    public function definition(): array
    {
        return [
            'station_id' => GasStation::factory(),
            'fuel_type_id' => FuelType::inRandomOrder()->value('id') ?? 1,
            'user_id' => User::factory(),
            'report_type' => 'price',
            'price' => fake()->randomFloat(4, 55, 65),
            'distance_m' => fake()->numberBetween(5, 200),
            'trust_score' => 0.5,
            'status' => PriceReport::STATUS_PENDING,
        ];
    }

    public function approved(): static
    {
        return $this->state(fn () => ['status' => PriceReport::STATUS_APPROVED, 'moderated_at' => now()]);
    }
}
