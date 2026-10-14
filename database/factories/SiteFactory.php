<?php

namespace Database\Factories;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

class SiteFactory extends Factory
{
    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            // Automatically associate with a random Customer if one isn't provided.
            'customer_id' => Customer::factory(),
            'name' => $this->faker->company,
            'address' => $this->faker->address,
            'metadata' => [],
        ];
    }

    /**
     * State for a specific site.
     */
    public function asPrimarySite(): static
    {
        return $this;
    }
}
