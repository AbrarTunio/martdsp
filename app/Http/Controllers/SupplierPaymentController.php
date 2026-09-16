<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSupplierPaymentRequest;
use App\Models\ActivityLog;
use App\Models\Supplier;
use App\Services\SupplierLedgerService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;

/**
 * Money handed to a supplier against their account.
 */
class SupplierPaymentController extends Controller
{
    public function __construct(private readonly SupplierLedgerService $ledger) {}

    public function store(StoreSupplierPaymentRequest $request, Supplier $supplier): RedirectResponse
    {
        $entry = $this->ledger->payment(
            supplier: $supplier,
            paisa: $request->amountPaisa(),
            method: $request->paymentMethod(),
            entryDate: $request->validated('paid_on'),
            note: $request->validated('note'),
        );

        ActivityLog::record('supplier.paid', $supplier, after: [
            'amount_paisa' => $entry->debit_paisa,
            'method' => $entry->method?->value,
            'balance_after_paisa' => $entry->balance_after_paisa,
        ]);

        return redirect()
            ->route('suppliers.show', $supplier)
            ->with('status', __('Payment of :amount to :name is recorded. You now owe :balance.', [
                'amount' => Money::withSymbol($entry->debit_paisa),
                'name' => $supplier->name,
                'balance' => Money::withSymbol(max(0, $entry->balance_after_paisa)),
            ]));
    }
}
