<?php

namespace Database\Factories;

use App\Enums\SaleStatus;
use App\Models\Register;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Sales written this way skip App\Services\SaleService — no stock moves and
 * no khata is written. Use the service in any test about what a sale does.
 *
 * @extends Factory<Sale>
 */
class SaleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => null,
            'register_id' => Register::factory(),
            'user_id' => User::factory()->cashier(),
            'status' => SaleStatus::Held,
            'prices_include_tax' => true,
        ];
    }

    /**
     * A finished sale for the given amount, paid in cash.
     */
    public function completed(int $totalPaisa = 50_000): static
    {
        return $this->state(fn (): array => [
            'status' => SaleStatus::Completed,
            'invoice_no' => (int) Sale::query()->max('invoice_no') + 1,
            'subtotal_paisa' => $totalPaisa,
            'total_paisa' => $totalPaisa,
            'paid_paisa' => $totalPaisa,
            'sold_at' => now(),
        ]);
    }

    public function held(): static
    {
        return $this->state(['status' => SaleStatus::Held]);
    }
}
