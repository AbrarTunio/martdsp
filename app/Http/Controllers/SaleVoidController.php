<?php

namespace App\Http\Controllers;

use App\Http\Requests\VoidSaleRequest;
use App\Models\Sale;
use App\Services\SaleService;
use Illuminate\Http\RedirectResponse;
use RuntimeException;

/**
 * Cancelling a bill that should never have been rung up. Supervisors only,
 * and only on the day it happened.
 */
class SaleVoidController extends Controller
{
    public function __construct(private readonly SaleService $sales) {}

    public function __invoke(VoidSaleRequest $request, Sale $sale): RedirectResponse
    {
        try {
            $this->sales->void($sale, $request->user(), (string) $request->validated('reason'));
        } catch (RuntimeException $exception) {
            return back()->withErrors(['reason' => $exception->getMessage()]);
        }

        return redirect()
            ->route('sales.show', $sale)
            ->with('status', __(':invoice is cancelled. The goods are back in stock.', [
                'invoice' => $sale->invoiceNumber(),
            ]));
    }
}
