<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\StockAdjustment;
use App\Models\StockAdjustmentItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockAdjustmentItem>
 */
class StockAdjustmentItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'stock_adjustment_id' => StockAdjustment::factory(),
            'product_id' => Product::factory(),
            'product_unit_id' => null,
            'qty' => 1,
            'qty_base' => 1,
            'system_qty_base' => null,
            'unit_cost_base_paisa' => 0,
            'value_paisa' => 0,
        ];
    }
}
