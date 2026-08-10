<?php

namespace Database\Factories;

use App\Models\Pallet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PalletBase>
 */
class PalletBaseFactory extends Factory
{
    public function definition(): array
    {
        return [
            'pallet_id' => Pallet::factory(),
            'name' => 'Base ' . fake()->unique()->numberBetween(1, 9999),
            'note' => null,
        ];
    }
}
