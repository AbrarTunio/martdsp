<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PurchaseItem>
 */
class PurchaseItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $qty = fake()->numberBetween(1, 10);
        $cost = fake()->numberBetween(1000, 50000);

        return [
            'purchase_id' => Purchase::factory(),
            'product_id' => Product::factory(),
            'product_unit_id' => null,
            'qty' => $qty,
            'bonus_qty' => 0,
            'qty_base' => $qty,
            'unit_cost_paisa' => $cost,
            'line_total_paisa' => $qty * $cost,
            'cost_base_paisa' => 0,
        ];
    }
}
