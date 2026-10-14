<?php

namespace Database\Factories;

use App\Models\Contact;
use App\Models\ContactMethod;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Contact>
 */
class ContactFactory extends Factory
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
            'contact_type' => 'primary',
            'first_name' => $this->faker->firstName(),
            'last_name' => $this->faker->lastName(),
            'email' => $this->faker->safeEmail(),
            'phone' => $this->faker->phoneNumber(),
            'metadata' => [],
        ];
    }

    public function withMethods(int $count = 1): static
    {
        return $this->has(ContactMethod::factory()->count($count), 'methods');
    }
}
