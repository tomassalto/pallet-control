<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Pallet>
 */
class PalletFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => 'PAL-' . fake()->unique()->numerify('##########'),
            'status' => 'open',
            'note' => null,
            'created_by' => null,
        ];
    }

    public function done(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'done',
        ]);
    }
}
