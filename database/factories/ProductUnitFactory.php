<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductUnit>
 */
class ProductUnitFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'unit_id' => Unit::factory(),
            'parent_unit_id' => null,
            'qty_per_parent' => 1,
            'conversion_factor' => 1,
            'sale_price_paisa' => fake()->numberBetween(1000, 50000),
            'mrp_paisa' => null,
            'is_base' => false,
            'is_default_sale' => false,
            'is_default_purchase' => false,
        ];
    }

    public function base(): static
    {
        return $this->state([
            'is_base' => true,
            'is_default_sale' => true,
            'conversion_factor' => 1,
            'parent_unit_id' => null,
            'qty_per_parent' => 1,
        ]);
    }

    public function holding(int $baseUnits): static
    {
        return $this->state(['conversion_factor' => $baseUnits]);
    }
}
