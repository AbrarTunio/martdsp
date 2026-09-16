<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PurchaseReturnItem>
 */
class PurchaseReturnItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'purchase_return_id' => PurchaseReturn::factory(),
            'product_id' => Product::factory(),
            'product_unit_id' => null,
            'qty' => 1,
            'qty_base' => 1,
            'unit_credit_paisa' => 0,
            'line_total_paisa' => 0,
            'cost_base_paisa' => 0,
        ];
    }
}
