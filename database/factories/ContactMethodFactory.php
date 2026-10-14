<?php

namespace Database\Factories;

use App\Enums\ContactMethodLabel;
use App\Enums\ContactMethodType;
use App\Models\Contact;
use App\Models\ContactMethod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContactMethod>
 */
class ContactMethodFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $type = $this->faker->randomElement(ContactMethodType::cases());

        return [
            'contact_id' => Contact::factory(),
            'method_type' => $type,
            'label' => $this->faker->randomElement(ContactMethodLabel::cases()),
            'value' => match ($type) {
                ContactMethodType::Email => $this->faker->safeEmail(),
                ContactMethodType::Phone => $this->faker->phoneNumber(),
                ContactMethodType::Teams => $this->faker->userName(),
            },
            'is_primary' => false,
        ];
    }
}
