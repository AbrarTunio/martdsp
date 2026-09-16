<?php

namespace App\Http\Controllers;

use App\Enums\PurchaseStatus;
use App\Enums\ReturnReason;
use App\Enums\ReturnSettlement;
use App\Http\Requests\StorePurchaseReturnRequest;
use App\Models\ActivityLog;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\PurchaseReturn;
use App\Models\Supplier;
use App\Services\PurchaseReturnService;
use App\Support\Money;
use App\Support\PurchaseRows;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

/**
 * Goods going back to the supplier — expired, damaged, the wrong flavour, or
 * simply not selling. Posted the moment it is saved.
 */
class PurchaseReturnController extends Controller
{
    public function __construct(private readonly PurchaseReturnService $returns) {}

    public function index(Request $request): View
    {
        Gate::authorize('supervise');

        return view('purchases.returns.index', [
            'returns' => PurchaseReturn::query()
                ->with(['supplier', 'user'])
                ->withCount('items')
                ->when($request->filled('reason'), fn (Builder $query) => $query->where('reason', $request->query('reason')))
                ->when($request->filled('supplier'), fn (Builder $query) => $query->where('supplier_id', $request->query('supplier')))
                ->latestFirst()
                ->paginate(25)
                ->withQueryString(),
            'reasons' => ReturnReason::options(),
            'suppliers' => Supplier::orderBy('name')->pluck('name', 'id'),
            'filters' => $request->only('reason', 'supplier'),
            'summary' => [
                'this_month_paisa' => (int) PurchaseReturn::whereBetween('returned_at', [now()->startOfMonth(), now()])->sum('total_paisa'),
                'loss_this_month_paisa' => (int) PurchaseReturn::whereBetween('returned_at', [now()->startOfMonth(), now()])
                    ->selectRaw('COALESCE(SUM(cost_value_paisa - total_paisa), 0) as loss')
                    ->value('loss'),
                'expired_this_month' => PurchaseReturn::where('reason', ReturnReason::Expired)
                    ->whereBetween('returned_at', [now()->startOfMonth(), now()])
                    ->count(),
            ],
        ]);
    }

    /**
     * Opened from a bill, the form starts with that bill's items and prices,
     * because "send back three of what came on Tuesday" is the usual request.
     */
    public function create(Request $request): View
    {
        Gate::authorize('supervise');

        $purchase = $request->filled('purchase')
            ? Purchase::with(['items.product.baseUnit', 'items.product.productUnits.unit'])
                ->where('status', PurchaseStatus::Received)
                ->find($request->query('purchase'))
            : null;

        $return = new PurchaseReturn([
            'supplier_id' => $purchase?->supplier_id ?? ($request->integer('supplier') ?: null),
            'purchase_id' => $purchase?->id,
            'reason' => ReturnReason::Expired,
            'settlement' => ReturnSettlement::Credit,
            'returned_at' => now(),
        ]);

        if ($return->supplier_id === null) {
            $return->settlement = ReturnSettlement::Cash;
        }

        return view('purchases.returns.create', [
            'return' => $return,
            'purchase' => $purchase,
            'rows' => $this->rowsFromOldInput() ?? ($purchase ? $this->rowsFromPurchase($purchase) : []),
            'suppliers' => Supplier::query()
                ->where(fn (Builder $query) => $query->where('is_active', true)->orWhereKey($return->supplier_id))
                ->orderBy('name')
                ->get()
                ->map(fn (Supplier $supplier): array => ['id' => $supplier->id, 'label' => $supplier->displayName()])
                ->all(),
            'reasons' => ReturnReason::options(),
            'settlements' => ReturnSettlement::options(),
        ]);
    }

    public function store(StorePurchaseReturnRequest $request): RedirectResponse
    {
        try {
            $return = $this->returns->post($request->returnAttributes(), $request->items(), $request->user());
        } catch (RuntimeException $exception) {
            return back()->withErrors(['items' => $exception->getMessage()])->withInput();
        }

        ActivityLog::record('purchase.returned', $return, after: [
            'reference' => $return->reference,
            'supplier_id' => $return->supplier_id,
            'total_paisa' => $return->total_paisa,
            'cost_value_paisa' => $return->cost_value_paisa,
        ]);

        return redirect()
            ->route('purchases.returns.show', $return)
            ->with('status', __(':reference is recorded. The goods are off the shelf and :amount is :settled.', [
                'reference' => $return->reference,
                'amount' => Money::withSymbol($return->total_paisa),
                'settled' => $return->settlement === ReturnSettlement::Cash
                    ? __('back in cash')
                    : __('off what you owe'),
            ]));
    }

    public function show(PurchaseReturn $return): View
    {
        Gate::authorize('supervise');

        $return->load([
            'supplier',
            'purchase',
            'items.product.baseUnit',
            'items.product.productUnits.unit',
            'items.productUnit.unit',
            'user',
        ]);

        return view('purchases.returns.show', [
            'return' => $return,
        ]);
    }

    /**
     * The bill's lines with nothing entered yet, priced at what was paid.
     *
     * @return array<int, array<string, mixed>>
     */
    private function rowsFromPurchase(Purchase $purchase): array
    {
        return $purchase->items
            ->unique('product_unit_id')
            ->map(fn (PurchaseItem $item): array => PurchaseRows::row($item->product, $item->product_unit_id, [
                'qty' => '',
                'unit_cost' => number_format($item->cost_base_paisa * $item->factor() / 100, 2, '.', ''),
            ]))
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    private function rowsFromOldInput(): ?array
    {
        $old = old('items');

        if (! is_array($old) || $old === []) {
            return null;
        }

        $products = Product::with(['baseUnit', 'productUnits.unit'])
            ->whereKey(collect($old)->pluck('product_id')->filter()->all())
            ->get()
            ->keyBy('id');

        return collect($old)
            ->map(function (array $item) use ($products): ?array {
                $product = $products->get((int) ($item['product_id'] ?? 0));

                return $product ? PurchaseRows::row($product, $item['product_unit_id'] ?? null, [
                    'qty' => (string) ($item['qty'] ?? ''),
                    'unit_cost' => (string) ($item['unit_credit'] ?? ''),
                ]) : null;
            })
            ->filter()
            ->values()
            ->all();
    }
}
