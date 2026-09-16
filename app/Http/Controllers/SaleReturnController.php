<?php

namespace App\Http\Controllers;

use App\Enums\RefundMethod;
use App\Enums\SaleReturnReason;
use App\Http\Requests\StoreSaleReturnRequest;
use App\Models\ActivityLog;
use App\Models\Register;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Services\SaleReturnService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

/**
 * Goods a customer brought back, always against the bill they were sold on.
 *
 * Posted the moment it is saved, because the customer is standing there. A
 * mistake is corrected the way every other posted thing is: with another
 * record, never by rubbing this one out.
 */
class SaleReturnController extends Controller
{
    public function __construct(private readonly SaleReturnService $returns) {}

    public function index(Request $request): View
    {
        Gate::authorize('supervise');

        $month = [now()->startOfMonth(), now()];

        return view('sales.returns.index', [
            'returns' => SaleReturn::query()
                ->with(['sale', 'customer', 'user'])
                ->withCount('items')
                ->when($request->filled('reason'), fn (Builder $query) => $query->where('reason', $request->query('reason')))
                ->when($request->filled('settlement'), fn (Builder $query) => $query->where('settlement', $request->query('settlement')))
                ->latestFirst()
                ->paginate(25)
                ->withQueryString(),
            'reasons' => SaleReturnReason::options(),
            'settlements' => RefundMethod::options(),
            'filters' => $request->only('reason', 'settlement'),
            'summary' => [
                'this_month_paisa' => (int) SaleReturn::whereBetween('returned_at', $month)->sum('total_paisa'),
                'this_month_count' => SaleReturn::whereBetween('returned_at', $month)->count(),
                'written_off_paisa' => (int) SaleReturn::whereBetween('returned_at', $month)
                    ->where('restocked', false)
                    ->sum('total_paisa'),
            ],
        ]);
    }

    /**
     * The bill with every line on it and how much of each is still to come
     * back, so the cashier only has to type quantities.
     */
    public function create(Sale $sale): View
    {
        Gate::authorize('supervise');

        $sale->load(['items.productUnit.unit', 'items.returnItems', 'customer', 'register', 'returns']);

        abort_unless($sale->isCompleted(), 404);

        return view('sales.returns.create', [
            'sale' => $sale,
            'reasons' => SaleReturnReason::options(),
            'settlements' => RefundMethod::options(),
            'registers' => Register::query()->active()->pluck('name', 'id'),
        ]);
    }

    public function store(StoreSaleReturnRequest $request, Sale $sale): RedirectResponse
    {
        try {
            $return = $this->returns->post(
                sale: $sale,
                lines: $request->lines(),
                user: $request->user(),
                reason: $request->reason(),
                settlement: $request->settlement(),
                register: $request->register(),
                note: $request->note(),
            );
        } catch (RuntimeException $exception) {
            return back()->withErrors(['items' => $exception->getMessage()])->withInput();
        }

        ActivityLog::record('sale.returned', $return, after: [
            'reference' => $return->reference,
            'invoice' => $sale->invoiceNumber(),
            'total_paisa' => $return->total_paisa,
            'settlement' => $return->settlement->value,
            'restocked' => $return->restocked,
        ]);

        return redirect()
            ->route('sales.returns.show', $return)
            ->with('status', $this->returns->describe($return));
    }

    public function show(SaleReturn $return): View
    {
        Gate::authorize('supervise');

        $return->load([
            'sale.register',
            'customer',
            'register',
            'user',
            'items.product.baseUnit',
            'items.product.productUnits.unit',
            'items.productUnit.unit',
            'items.saleItem',
        ]);

        return view('sales.returns.show', [
            'return' => $return,
        ]);
    }
}
