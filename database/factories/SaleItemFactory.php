<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SaleItem>
 */
class SaleItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $price = fake()->numberBetween(1000, 50_000);

        return [
            'sale_id' => Sale::factory(),
            'product_id' => Product::factory(),
            'product_unit_id' => null,
            'name' => fake()->words(2, true),
            'unit_name' => 'Piece',
            'qty' => 1,
            'qty_base' => 1,
            'unit_price_paisa' => $price,
            'gross_paisa' => $price,
            'discount' => null,
            'discount_paisa' => 0,
            'bill_discount_paisa' => 0,
            'tax_rate' => 0,
            'tax_paisa' => 0,
            'line_total_paisa' => $price,
            'cost_at_sale_base_paisa' => intdiv($price * 3, 4),
        ];
    }
}
