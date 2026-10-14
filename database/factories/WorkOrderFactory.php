<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class WorkOrderFactory extends Factory
{
    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            // Automatically associate with a random Site if one isn't provided.
            'site_id' => Site::factory(),
            'title' => $this->faker->sentence,
            'description' => $this->faker->paragraph,
            'status' => config('work_orders.default_status'),
            'priority' => 'normal',
            'metadata' => [],
        ];
    }

    /**
     * State for an urgent work order.
     */
    public function asUrgent(): static
    {
        return $this->modify(['priority' => 'high']);
    }
}
