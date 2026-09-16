<?php

namespace Database\Factories;

use App\Enums\RefundMethod;
use App\Enums\SaleReturnReason;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Returns written this way skip App\Services\SaleReturnService — no stock
 * moves, no drawer entry and no khata credit. Use the service in any test
 * about what a return does.
 *
 * @extends Factory<SaleReturn>
 */
class SaleReturnFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sale_id' => Sale::factory()->completed(),
            'customer_id' => null,
            'register_id' => null,
            'reason' => SaleReturnReason::ChangedMind,
            'settlement' => RefundMethod::Cash,
            'restocked' => true,
            'total_paisa' => 0,
            'tax_paisa' => 0,
            'cost_value_paisa' => 0,
            'user_id' => User::factory()->cashier(),
            'returned_at' => now(),
        ];
    }

    public function onKhata(): static
    {
        return $this->state(['settlement' => RefundMethod::Khata]);
    }

    /**
     * Goods that came back unfit to sell, so nothing went on the shelf.
     */
    public function expired(): static
    {
        return $this->state([
            'reason' => SaleReturnReason::Expired,
            'restocked' => false,
        ]);
    }
}
