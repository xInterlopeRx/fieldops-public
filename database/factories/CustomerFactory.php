<?php

namespace Database\Factories;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

class CustomerFactory extends Factory
{
    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            // Automatically associate with a random Organization if one isn't provided.
            'organization_id' => Organization::factory(),
            'name' => $this->faker->company,
            'email' => $this->faker->unique()->safeEmail,
            'phone' => $this->faker->phoneNumber,
            'address' => $this->faker->address,
            'metadata' => [],
        ];
    }

    /**
     * State for a specific child company.
     */
    public function asChildCompany(): static
    {
        return $this;
    }
}
