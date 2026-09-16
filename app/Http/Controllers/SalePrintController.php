<?php

namespace App\Http\Controllers;

use App\Enums\SaleStatus;
use App\Models\Sale;
use App\Services\PrintService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

/**
 * Reprint a slip on the counter printer.
 *
 * The browser path already exists and works from a phone; this one is for the
 * PC standing next to the thermal printer, where a customer asking for
 * another copy should cost one tap and no print dialog.
 */
class SalePrintController extends Controller
{
    public function __construct(private readonly PrintService $printing) {}

    public function __invoke(Sale $sale): RedirectResponse
    {
        Gate::authorize('view-sale', $sale);

        abort_if($sale->status === SaleStatus::Held, 404);

        try {
            $printer = $this->printing->receipt($sale);
        } catch (RuntimeException $failure) {
            return back()->withErrors(['printer' => $failure->getMessage()]);
        }

        return back()->with('status', __('Bill :invoice was sent to :printer.', [
            'invoice' => $sale->invoiceNumber(),
            'printer' => $printer->name,
        ]));
    }
}
