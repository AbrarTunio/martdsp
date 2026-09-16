<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreKhataPaymentRequest;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Services\KhataService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use RuntimeException;

/**
 * Money handed over against a khata.
 *
 * Open to everyone on the till: khata money is usually pressed into the
 * cashier's hand on the way out, and asking the customer to wait for the owner
 * is how a payment ends up unwritten.
 */
class CustomerPaymentController extends Controller
{
    public function __construct(private readonly KhataService $khata) {}

    public function store(StoreKhataPaymentRequest $request, Customer $customer): RedirectResponse
    {
        try {
            $entry = $this->khata->payment(
                customer: $customer,
                paisa: $request->amountPaisa(),
                method: $request->tender(),
                register: $request->register(),
                entryDate: $request->validated('paid_on'),
                note: $request->validated('note'),
                user: $request->user(),
            );
        } catch (RuntimeException $error) {
            return back()->withErrors(['amount' => $error->getMessage()])->withInput();
        }

        ActivityLog::record('customer.paid', $customer, after: [
            'amount_paisa' => $entry->credit_paisa,
            'method' => $entry->method?->value,
            'balance_after_paisa' => $entry->balance_after_paisa,
        ]);

        $remaining = (int) $entry->balance_after_paisa;

        return redirect()
            ->route('customers.show', $customer)
            ->with('status', $remaining > 0
                ? __(':amount received from :name. :balance is still on their khata.', [
                    'amount' => Money::withSymbol($entry->credit_paisa),
                    'name' => $customer->name,
                    'balance' => Money::withSymbol($remaining),
                ])
                : __(':amount received from :name. Their khata is clear.', [
                    'amount' => Money::withSymbol($entry->credit_paisa),
                    'name' => $customer->name,
                ]));
    }
}
