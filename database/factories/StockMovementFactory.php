<?php

namespace Database\Factories;

use App\Enums\MovementType;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockMovement>
 */
class StockMovementFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'product_unit_id' => null,
            'qty_base' => fake()->numberBetween(1, 500),
            'type' => MovementType::Purchase,
            'unit_cost_base_paisa' => fake()->numberBetween(500, 50000),
            'avg_cost_after_paisa' => 0,
            'balance_after_base' => 0,
            'user_id' => User::factory(),
            'occurred_at' => now(),
        ];
    }

    public function ofType(MovementType $type): static
    {
        return $this->state(['type' => $type]);
    }

    public function outgoing(int $qtyBase): static
    {
        return $this->state([
            'type' => MovementType::Sale,
            'qty_base' => -abs($qtyBase),
        ]);
    }

    public function incoming(int $qtyBase, int $unitCostPaisa = 1000): static
    {
        return $this->state([
            'type' => MovementType::Purchase,
            'qty_base' => abs($qtyBase),
            'unit_cost_base_paisa' => $unitCostPaisa,
        ]);
    }
}
