<?php

namespace Database\Factories;

use App\Enums\CustomerEntryType;
use App\Models\Customer;
use App\Models\CustomerLedgerEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Rows written this way skip App\Services\KhataService, so the customer's
 * cached balance will not follow them. Use the service in any test that
 * cares about the balance.
 *
 * @extends Factory<CustomerLedgerEntry>
 */
class CustomerLedgerEntryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $paisa = fake()->numberBetween(100, 500_000);

        return [
            'customer_id' => Customer::factory(),
            'type' => CustomerEntryType::SaleCredit,
            'method' => null,
            'debit_paisa' => $paisa,
            'credit_paisa' => 0,
            'balance_after_paisa' => $paisa,
            'entry_date' => today(),
            'due_date' => today()->addDays(30),
            'note' => null,
        ];
    }
}
