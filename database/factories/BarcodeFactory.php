<?php

namespace Database\Factories;

use App\Models\Barcode;
use App\Models\ProductUnit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Barcode>
 */
class BarcodeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_unit_id' => ProductUnit::factory(),
            /** 896 is the Pakistani GS1 prefix, so test data looks like the real thing. */
            'code' => '896'.fake()->unique()->numerify('##########'),
            'is_primary' => false,
        ];
    }

    public function primary(): static
    {
        return $this->state(['is_primary' => true]);
    }
}
