<?php

namespace Database\Factories;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Order>
 */
class OrderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'code' => (string) fake()->unique()->numberBetween(10000, 999999),
            'status' => 'open',
            'note' => null,
        ];
    }

    public function done(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'done',
        ]);
    }
}
