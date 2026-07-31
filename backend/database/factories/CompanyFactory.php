<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\User\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Company> */
class CompanyFactory extends Factory
{
    protected $model = Company::class;

    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'legal_name' => fake()->company().' Inc.',
            'type' => fake()->randomElement(['logistics', 'trucking', 'delivery', 'taxi']),
            'contact_email' => fake()->companyEmail(),
            'contact_phone' => '+632'.fake()->numerify('########'),
            'subscription_tier' => 'business',
            'is_active' => true,
        ];
    }
}
