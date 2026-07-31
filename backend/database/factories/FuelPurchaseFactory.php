<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Expense\Models\FuelPurchase;
use App\Domain\Pricing\Models\FuelType;
use App\Domain\User\Models\User;
use App\Domain\Vehicle\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FuelPurchase> */
class FuelPurchaseFactory extends Factory
{
    protected $model = FuelPurchase::class;

    public function definition(): array
    {
        $litres = fake()->randomFloat(3, 20, 60);
        $pricePerLitre = fake()->randomFloat(4, 55, 65);

        return [
            'vehicle_id' => Vehicle::factory(),
            'user_id' => User::factory(),
            'fuel_type_id' => FuelType::inRandomOrder()->value('id') ?? 1,
            'litres' => $litres,
            'price_per_litre' => $pricePerLitre,
            'total_cost' => round($litres * $pricePerLitre, 2),
            'odometer' => fake()->randomFloat(2, 10_000, 150_000),
            'is_full_tank' => true,
            'purchased_at' => fake()->dateTimeBetween('-6 months', 'now'),
        ];
    }
}
