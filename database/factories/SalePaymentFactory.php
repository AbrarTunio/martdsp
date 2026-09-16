<?php

namespace Database\Factories;

use App\Enums\TenderType;
use App\Models\Sale;
use App\Models\SalePayment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SalePayment>
 */
class SalePaymentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $amount = fake()->numberBetween(1000, 50_000);

        return [
            'sale_id' => Sale::factory(),
            'method' => TenderType::Cash,
            'amount_paisa' => $amount,
            'tendered_paisa' => $amount,
            'reference' => null,
        ];
    }

    public function method(TenderType $method): static
    {
        return $this->state(['method' => $method]);
    }
}
