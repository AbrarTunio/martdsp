<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => Str::title(fake()->unique()->words(2, true)),
            'name_ur' => null,
            'category_id' => null,
            'brand_id' => null,
            'base_unit_id' => Unit::factory(),
            'tax_rate' => 18.00,
            'is_weighted' => false,
            'track_batches' => false,
            'track_expiry' => false,
            'reorder_level_base' => 0,
            'reorder_qty_base' => 0,
            'is_active' => true,
        ];
    }

    public function weighted(): static
    {
        return $this->state(['is_weighted' => true]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    /**
     * Stock and cost are written by the movement ledger in Phase 2, never by
     * mass assignment, so tests that need a balance set it explicitly.
     */
    public function withStock(int $qtyBase): static
    {
        return $this->afterCreating(function (Product $product) use ($qtyBase): void {
            $product->forceFill(['stock_qty_base' => $qtyBase])->save();
        });
    }

    /**
     * Goods that go off — milk, bread, medicine. Stock of these is kept in
     * layers so the shopkeeper can be told what to sell first.
     */
    public function perishable(): static
    {
        return $this->state([
            'track_batches' => true,
            'track_expiry' => true,
        ]);
    }

    public function reorderAt(int $levelBase, int $qtyBase = 0): static
    {
        return $this->state([
            'reorder_level_base' => $levelBase,
            'reorder_qty_base' => $qtyBase,
        ]);
    }
}
