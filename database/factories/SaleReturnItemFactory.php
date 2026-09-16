<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SaleReturnItem>
 */
class SaleReturnItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $refund = fake()->numberBetween(1000, 50_000);

        return [
            'sale_return_id' => SaleReturn::factory(),
            'sale_item_id' => SaleItem::factory(),
            'product_id' => Product::factory(),
            'product_unit_id' => null,
            'qty' => 1,
            'qty_base' => 1,
            'unit_refund_paisa' => $refund,
            'tax_paisa' => 0,
            'line_total_paisa' => $refund,
            'cost_base_paisa' => intdiv($refund * 3, 4),
        ];
    }
}
