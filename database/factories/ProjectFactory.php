<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'project_number' => 'P'.fake()->unique()->numerify('#####'),
            'name' => fake()->sentence(3),
            'notes' => fake()->optional()->paragraph(),
            'status' => 'active',
        ];
    }
}
