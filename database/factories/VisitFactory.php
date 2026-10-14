<?php

namespace Database\Factories;

use App\Models\Visit;
use Illuminate\Database\Eloquent\Factories\Factory;

class VisitFactory extends Factory
{
    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            // Automatically associate with a random WorkOrder if one isn't provided.
            'work_order_id' => WorkOrder::factory(),
            'scheduled_at' => $this->faker->dateTimeBetween('-1 month', '+1 month'),
            'completed_at' => null,
            'notes' => '',
            'status' => 'scheduled',
            'metadata' => [],
        ];
    }

    /**
     * State for a completed visit.
     */
    public function asCompleted(): static
    {
        return $this->modify([
            'completed_at' => now(),
            'status' => 'completed',
        ]);
    }
}
