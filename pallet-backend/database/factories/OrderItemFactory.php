<?php

namespace Database\Factories;

use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\OrderItem>
 */
class OrderItemFactory extends Factory
{
    public function definition(): array
    {
        $ean = (string) fake()->unique()->numerify('#############');

        return [
            'order_id' => Order::factory(),
            'ean' => $ean,
            'ean_last4' => substr($ean, -4),
            'description' => fake()->words(3, true),
            'qty' => fake()->numberBetween(10, 100),
            'status' => 'pending',
            'done_qty' => 0,
            'price' => null,
        ];
    }
}
