<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class OrganizationFactory extends Factory
{
    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->company,
            'tax_id' => $this->faker->unique()->numerify('##########'), // Example 10-digit tax ID
            'metadata' => [],
        ];
    }
}
