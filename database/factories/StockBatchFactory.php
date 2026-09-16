<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\StockBatch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A layer of stock on the shelf.
 *
 * This writes the row on its own; it does not move the product's balance. A
 * test that needs the shelf and the layers to agree should take the goods in
 * through a purchase, which is what does both.
 *
 * @extends Factory<StockBatch>
 */
class StockBatchFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'purchase_item_id' => null,
            'batch_no' => strtoupper(fake()->bothify('B##??')),
            'expiry_date' => today()->addMonths(6),
            'received_base' => 100,
            'qty_base' => 100,
            'cost_base_paisa' => 2_000,
            'received_at' => now(),
        ];
    }

    /**
     * Goods that went off some days ago.
     */
    public function expired(int $daysAgo = 3): static
    {
        return $this->state(['expiry_date' => today()->subDays($daysAgo)]);
    }

    /**
     * Goods with their last few days left — what the expiry list is for.
     */
    public function expiringIn(int $days): static
    {
        return $this->state(['expiry_date' => today()->addDays($days)]);
    }

    /**
     * A layer that has been sold out.
     */
    public function emptied(): static
    {
        return $this->state(['qty_base' => 0]);
    }

    /**
     * Tracked by its number alone, with no date on the carton.
     */
    public function undated(): static
    {
        return $this->state(['expiry_date' => null]);
    }
}
