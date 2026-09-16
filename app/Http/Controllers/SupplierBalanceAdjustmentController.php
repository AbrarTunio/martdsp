<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSupplierAdjustmentRequest;
use App\Models\ActivityLog;
use App\Models\Supplier;
use App\Services\SupplierLedgerService;
use Illuminate\Http\RedirectResponse;

/**
 * A correction to what the shop owes a supplier — a bill the salesman forgot
 * to hand over, a rounding off agreed on the phone — always with the reason
 * written against it.
 */
class SupplierBalanceAdjustmentController extends Controller
{
    public function __construct(private readonly SupplierLedgerService $ledger) {}

    public function store(StoreSupplierAdjustmentRequest $request, Supplier $supplier): RedirectResponse
    {
        $before = (int) $supplier->balance_paisa;

        $entry = $this->ledger->adjust($supplier, $request->signedPaisa(), $request->validated('note'));

        ActivityLog::record('supplier.adjusted', $supplier,
            ['balance_paisa' => $before],
            ['balance_paisa' => $entry->balance_after_paisa, 'note' => $entry->note],
        );

        return redirect()
            ->route('suppliers.show', $supplier)
            ->with('status', __('The balance was corrected. The reason is on the statement.'));
    }
}
