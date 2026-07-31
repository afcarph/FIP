<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Station\Models\Brand;
use App\Domain\Station\Models\City;
use App\Domain\Station\Models\GasStation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<GasStation> */
class GasStationFactory extends Factory
{
    protected $model = GasStation::class;

    public function definition(): array
    {
        $name = fake()->company().' Station '.fake()->unique()->numberBetween(1, 99999);

        return [
            'brand_id' => Brand::inRandomOrder()->value('id') ?? Brand::factory(),
            'city_id' => City::inRandomOrder()->value('id') ?? City::factory(),
            'name' => $name,
            'address_line' => fake()->streetAddress(),
            // Coordinates land inside Metro Manila so radius tests are realistic.
            'latitude' => fake()->randomFloat(7, 14.40, 14.75),
            'longitude' => fake()->randomFloat(7, 120.95, 121.12),
            'phone' => '+632'.fake()->numerify('########'),
            'is_24_hours' => fake()->boolean(40),
            'has_ev_charging' => fake()->boolean(20),
            'status' => 'active',
            'verified_at' => now(),
        ];
    }

    public function closed(): static
    {
        return $this->state(fn () => ['status' => 'temporarily_closed']);
    }
}
