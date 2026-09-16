<?php

namespace App\Http\Controllers;

use App\Enums\PaymentMethod;
use App\Enums\PurchaseStatus;
use App\Http\Requests\StorePurchaseRequest;
use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Supplier;
use App\Models\Unit;
use App\Services\PurchaseService;
use App\Services\ScanService;
use App\Support\Money;
use App\Support\PurchaseRows;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Deliveries, entered by scanning each carton as it comes off the van.
 *
 * A purchase is a draft until it is received. A draft is just a list — it
 * can be scanned half now and half after lunch, edited, or thrown away.
 * Receiving is the one-way door where stock, cost and the supplier's account
 * all move together; see App\Services\PurchaseService.
 */
class PurchaseController extends Controller
{
    public function __construct(private readonly PurchaseService $purchases) {}

    public function index(Request $request): View
    {
        Gate::authorize('supervise');

        return view('purchases.index', [
            'purchases' => $this->filtered($request),
            'statuses' => PurchaseStatus::options(),
            'suppliers' => Supplier::orderBy('name')->pluck('name', 'id'),
            'filters' => $request->only('q', 'status', 'supplier'),
            'summary' => [
                'drafts' => Purchase::where('status', PurchaseStatus::Draft)->count(),
                'this_month_paisa' => (int) Purchase::received()
                    ->whereBetween('purchase_date', [today()->startOfMonth(), today()])
                    ->sum('total_paisa'),
                'owed_paisa' => (int) Supplier::where('balance_paisa', '>', 0)->sum('balance_paisa'),
            ],
        ]);
    }

    public function create(Request $request): View
    {
        Gate::authorize('supervise');

        $purchase = new Purchase([
            'supplier_id' => $request->integer('supplier') ?: null,
            'purchase_date' => today(),
        ]);

        return view('purchases.create', $this->formData($purchase, $this->rowsFromOldInput() ?? []));
    }

    public function store(StorePurchaseRequest $request): RedirectResponse
    {
        $purchase = DB::transaction(function () use ($request): Purchase {
            $purchase = Purchase::create($request->purchaseAttributes() + [
                'status' => PurchaseStatus::Draft,
                'user_id' => $request->user()->getKey(),
            ]);

            $purchase->items()->createMany($request->items());

            return $this->purchases->refreshTotals($purchase);
        });

        ActivityLog::record('purchase.created', $purchase, after: [
            'reference' => $purchase->reference,
            'supplier_id' => $purchase->supplier_id,
            'total_paisa' => $purchase->total_paisa,
        ]);

        if ($request->wantsToReceive()) {
            return $this->receive($purchase);
        }

        return redirect()
            ->route('purchases.show', $purchase)
            ->with('status', __(':reference is saved but not received. Stock has not changed yet.', [
                'reference' => $purchase->reference,
            ]));
    }

    public function show(Purchase $purchase): View
    {
        Gate::authorize('supervise');

        $purchase->load([
            'supplier',
            'items.product.baseUnit',
            'items.product.productUnits.unit',
            'items.productUnit.unit',
            'user',
            'receiver',
            'returns',
        ]);

        return view('purchases.show', [
            'purchase' => $purchase,
        ]);
    }

    public function edit(Purchase $purchase): View
    {
        Gate::authorize('supervise');

        $this->refuseUnlessDraft($purchase);

        $purchase->load(['items.product.baseUnit', 'items.product.productUnits.unit']);

        return view('purchases.edit', $this->formData(
            $purchase,
            $this->rowsFromOldInput() ?? $this->rowsFrom($purchase),
        ));
    }

    public function update(StorePurchaseRequest $request, Purchase $purchase): RedirectResponse
    {
        Gate::authorize('supervise');

        $this->refuseUnlessDraft($purchase);

        DB::transaction(function () use ($request, $purchase): void {
            $purchase->update($request->purchaseAttributes());

            $purchase->items()->delete();
            $purchase->items()->createMany($request->items());

            $this->purchases->refreshTotals($purchase);
        });

        ActivityLog::record('purchase.updated', $purchase, after: [
            'total_paisa' => $purchase->total_paisa,
        ]);

        if ($request->wantsToReceive()) {
            return $this->receive($purchase);
        }

        return redirect()
            ->route('purchases.show', $purchase)
            ->with('status', __(':reference was saved.', ['reference' => $purchase->reference]));
    }

    /**
     * Take the delivery in. From here the purchase is part of three ledgers
     * and is corrected with a return, never an edit.
     */
    public function receive(Purchase $purchase): RedirectResponse
    {
        Gate::authorize('supervise');

        try {
            $this->purchases->receive($purchase, request()->user());
        } catch (RuntimeException $exception) {
            return redirect()
                ->route('purchases.show', $purchase)
                ->withErrors(['purchase' => $exception->getMessage()]);
        }

        ActivityLog::record('purchase.received', $purchase, after: [
            'reference' => $purchase->reference,
            'total_paisa' => $purchase->total_paisa,
            'paid_paisa' => $purchase->paid_paisa,
        ]);

        return redirect()
            ->route('purchases.show', $purchase)
            ->with('status', $this->receivedMessage($purchase));
    }

    /**
     * Abandon a draft. Nothing it held ever reached the shelf.
     */
    public function destroy(Purchase $purchase): RedirectResponse
    {
        Gate::authorize('supervise');

        try {
            $this->purchases->cancel($purchase);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['purchase' => $exception->getMessage()]);
        }

        ActivityLog::record('purchase.cancelled', $purchase);

        return redirect()
            ->route('purchases.index')
            ->with('status', __(':reference was cancelled. Nothing was changed.', [
                'reference' => $purchase->reference,
            ]));
    }

    /**
     * What a scanned code means on a delivery. An unknown barcode is not an
     * error here: it is a new product arriving, and the answer says so, so
     * the form can offer to add it on the spot.
     */
    public function lookup(Request $request, ScanService $scanner): JsonResponse
    {
        Gate::authorize('supervise');

        $code = trim((string) $request->query('code', ''));

        if ($code === '') {
            return response()->json(['found' => false, 'code' => '']);
        }

        $productUnit = $scanner->resolve($code);
        $product = $productUnit?->product;

        /* A string of digits is a barcode nobody has seen yet, not a name
           to go searching for — a partial match would put the wrong item on
           the bill. */
        if (! $product && ! $this->looksLikeABarcode($code)) {
            $product = $scanner->search($code, 1)->first();
        }

        if (! $product) {
            return response()->json([
                'found' => false,
                'code' => $code,
                'is_barcode' => $this->looksLikeABarcode($code),
            ]);
        }

        return response()->json([
            'found' => true,
            'row' => PurchaseRows::row($product, $productUnit?->id),
        ]);
    }

    private function looksLikeABarcode(string $code): bool
    {
        return (bool) preg_match('/^\d{6,}$/', $code);
    }

    private function receivedMessage(Purchase $purchase): string
    {
        $purchase->refresh()->load('supplier');

        $lines = $purchase->items()->count();

        if (! $purchase->supplier) {
            return __(':reference is received. :lines items went onto the shelf.', [
                'reference' => $purchase->reference,
                'lines' => $lines,
            ]);
        }

        return __(':reference is received. :lines items went onto the shelf, and you now owe :name :balance.', [
            'reference' => $purchase->reference,
            'lines' => $lines,
            'name' => $purchase->supplier->name,
            'balance' => Money::withSymbol(max(0, (int) $purchase->supplier->balance_paisa)),
        ]);
    }

    /**
     * @return LengthAwarePaginator<int, Purchase>
     */
    private function filtered(Request $request): LengthAwarePaginator
    {
        $term = trim((string) $request->query('q'));

        return Purchase::query()
            ->with('supplier')
            ->withCount('items')
            ->when($term !== '', fn (Builder $query) => $query->where(function (Builder $query) use ($term): void {
                $query->where('reference', 'like', "%{$term}%")
                    ->orWhere('invoice_no', 'like', "%{$term}%")
                    ->orWhereHas('supplier', fn (Builder $supplier) => $supplier->search($term));
            }))
            ->when($request->filled('status'), fn (Builder $query) => $query->where('status', $request->query('status')))
            ->when($request->filled('supplier'), fn (Builder $query) => $query->where('supplier_id', $request->query('supplier')))
            ->latestFirst()
            ->paginate(25)
            ->withQueryString();
    }

    /**
     * Everything the create and edit forms need, including what the
     * quick-add sheet offers for a product the shop has never stocked.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function formData(Purchase $purchase, array $rows): array
    {
        $suppliers = Supplier::query()
            ->where(fn (Builder $query) => $query->where('is_active', true)->orWhereKey($purchase->supplier_id))
            ->orderBy('name')
            ->get()
            ->map(fn (Supplier $supplier): array => PurchaseRows::supplier($supplier))
            ->all();

        return [
            'purchase' => $purchase,
            'rows' => $rows,
            'suppliers' => $suppliers,
            'methods' => PaymentMethod::options(),
            'units' => Unit::orderBy('name')->get(['id', 'name', 'short_name']),
            'categories' => Category::orderBy('name')->pluck('name', 'id'),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rowsFrom(Purchase $purchase): array
    {
        return $purchase->items
            ->map(fn (PurchaseItem $item): array => PurchaseRows::row($item->product, $item->product_unit_id, [
                'qty' => $item->qty > 0 ? (string) $item->qty : '',
                'bonus_qty' => $item->bonus_qty > 0 ? (string) $item->bonus_qty : '',
                'unit_cost' => number_format($item->unit_cost_paisa / 100, 2, '.', ''),
                'batch_no' => (string) $item->batch_no,
                'expiry_date' => (string) $item->expiry_date?->toDateString(),
            ]))
            ->all();
    }

    /**
     * After a validation failure the form must come back with every scanned
     * line, including the item names — which only the database knows.
     *
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
                    'bonus_qty' => (string) ($item['bonus_qty'] ?? ''),
                    'unit_cost' => (string) ($item['unit_cost'] ?? ''),
                    'batch_no' => (string) ($item['batch_no'] ?? ''),
                    'expiry_date' => (string) ($item['expiry_date'] ?? ''),
                ]) : null;
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @throws HttpException
     */
    private function refuseUnlessDraft(Purchase $purchase): void
    {
        abort_unless($purchase->isEditable(), 403, __('This purchase has already been dealt with. Correct it with a return instead.'));
    }
}
