<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Pricing\Models\FuelType;
use App\Domain\Pricing\Models\StationPrice;
use App\Domain\Station\Models\GasStation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StationPrice> */
class StationPriceFactory extends Factory
{
    protected $model = StationPrice::class;

    public function definition(): array
    {
        return [
            'station_id' => GasStation::factory(),
            'fuel_type_id' => FuelType::inRandomOrder()->value('id') ?? 1,
            'price' => fake()->randomFloat(4, 55, 65),
            'source' => 'operator',
            'confidence' => 1.0,
            'effective_at' => now(),
            'verified_at' => now(),
        ];
    }
}
