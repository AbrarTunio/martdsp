<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\StockTake;
use App\Models\StockTakeItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockTakeItem>
 */
class StockTakeItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'stock_take_id' => StockTake::factory(),
            'product_id' => Product::factory(),
            'product_unit_id' => null,
            'counted_qty' => 1,
            'counted_base' => 1,
            'was_counted' => true,
            'system_qty_base' => null,
            'variance_base' => 0,
            'unit_cost_base_paisa' => 0,
            'variance_value_paisa' => 0,
        ];
    }
}
