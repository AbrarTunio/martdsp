<?php

namespace Database\Factories;

use App\Enums\SupplierEntryType;
use App\Models\Supplier;
use App\Models\SupplierLedgerEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Ledger rows are written by App\Services\SupplierLedgerService, which keeps
 * the running balance true. This factory is for tests that only need a row
 * to exist.
 *
 * @extends Factory<SupplierLedgerEntry>
 */
class SupplierLedgerEntryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'supplier_id' => Supplier::factory(),
            'type' => SupplierEntryType::Adjustment,
            'method' => null,
            'debit_paisa' => 0,
            'credit_paisa' => 0,
            'balance_after_paisa' => 0,
            'entry_date' => today(),
            'note' => null,
        ];
    }
}
