<?php

namespace Database\Factories;

use App\Enums\ReturnReason;
use App\Enums\ReturnSettlement;
use App\Models\PurchaseReturn;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PurchaseReturn>
 */
class PurchaseReturnFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'supplier_id' => Supplier::factory(),
            'purchase_id' => null,
            'reason' => ReturnReason::Expired,
            'settlement' => ReturnSettlement::Credit,
            'total_paisa' => 0,
            'cost_value_paisa' => 0,
            'user_id' => User::factory()->owner(),
            'returned_at' => now(),
        ];
    }
}
