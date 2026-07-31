<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Pricing\Models\FuelType;
use App\Domain\User\Models\User;
use App\Domain\Vehicle\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Vehicle> */
class VehicleFactory extends Factory
{
    protected $model = Vehicle::class;

    public function definition(): array
    {
        return [
            'owner_id' => User::factory(),
            'fuel_type_id' => FuelType::inRandomOrder()->value('id') ?? 1,
            'plate_number' => strtoupper(fake()->unique()->bothify('???-####')),
            'vehicle_type' => 'car',
            'year' => fake()->numberBetween(2015, 2026),
            'color' => fake()->safeColorName(),
            'transmission' => 'automatic',
            'tank_capacity' => fake()->randomFloat(2, 35, 80),
            'current_odometer' => fake()->randomFloat(2, 10_000, 150_000),
            'baseline_km_per_litre' => fake()->randomFloat(2, 6, 16),
            'status' => 'active',
        ];
    }

    public function forCompany(int $companyId): static
    {
        return $this->state(fn () => ['owner_id' => null, 'company_id' => $companyId]);
    }

    public function truck(): static
    {
        return $this->state(fn () => [
            'vehicle_type' => 'truck',
            'tank_capacity' => 200,
            'baseline_km_per_litre' => 3.8,
        ]);
    }
}
