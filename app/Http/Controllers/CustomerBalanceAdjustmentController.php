<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreKhataAdjustmentRequest;
use App\Http\Requests\StoreKhataWriteOffRequest;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Services\KhataService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use RuntimeException;

/**
 * Corrections to a khata: a line the shop got wrong, and money the owner has
 * decided will never come back.
 *
 * Nothing here rubs anything out. Both write one more line onto the khata with
 * the reason against it, so the customer's own page and this one can still be
 * read side by side.
 */
class CustomerBalanceAdjustmentController extends Controller
{
    public function __construct(private readonly KhataService $khata) {}

    public function store(StoreKhataAdjustmentRequest $request, Customer $customer): RedirectResponse
    {
        $before = (int) $customer->balance_paisa;

        $entry = $this->khata->adjust($customer, $request->signedPaisa(), $request->validated('note'));

        ActivityLog::record('customer.adjusted', $customer,
            ['balance_paisa' => $before],
            ['balance_paisa' => $entry->balance_after_paisa, 'note' => $entry->note],
        );

        return redirect()
            ->route('customers.show', $customer)
            ->with('status', __('The khata was corrected. The reason is on the statement.'));
    }

    public function writeOff(StoreKhataWriteOffRequest $request, Customer $customer): RedirectResponse
    {
        $before = (int) $customer->balance_paisa;

        try {
            $entry = $this->khata->writeOff($customer, $request->amountPaisa(), $request->validated('note'));
        } catch (RuntimeException $error) {
            return back()->withErrors(['amount' => $error->getMessage()])->withInput();
        }

        ActivityLog::record('customer.written_off', $customer,
            ['balance_paisa' => $before],
            ['balance_paisa' => $entry->balance_after_paisa, 'note' => $entry->note],
        );

        return redirect()
            ->route('customers.show', $customer)
            ->with('status', __(':amount was written off :name\'s khata.', [
                'amount' => Money::withSymbol($entry->credit_paisa),
                'name' => $customer->name,
            ]));
    }
}
