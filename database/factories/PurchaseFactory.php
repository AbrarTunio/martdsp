<?php

namespace Database\Factories;

use App\Enums\PurchaseStatus;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Purchase>
 */
class PurchaseFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'supplier_id' => Supplier::factory(),
            'invoice_no' => fake()->optional()->numerify('INV-####'),
            'purchase_date' => today(),
            'status' => PurchaseStatus::Draft,
            'user_id' => User::factory()->owner(),
        ];
    }

    /**
     * Bought for cash in the market, with nobody to owe.
     */
    public function cash(): static
    {
        return $this->state(['supplier_id' => null]);
    }

    /**
     * A received purchase written straight to the table, for tests that need
     * one to exist rather than to prove receiving works.
     */
    public function received(): static
    {
        return $this->state([
            'status' => PurchaseStatus::Received,
            'received_at' => now(),
        ]);
    }
}
