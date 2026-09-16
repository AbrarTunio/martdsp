<?php

namespace App\Http\Controllers;

use App\Enums\AdjustmentReason;
use App\Enums\AdjustmentStatus;
use App\Http\Requests\StoreStockAdjustmentRequest;
use App\Models\ActivityLog;
use App\Models\Product;
use App\Models\StockAdjustment;
use App\Services\StockAdjustmentService;
use App\Support\Packaging;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Corrections to stock, made by hand and with a reason attached.
 *
 * The whole area needs a supervisor. A cashier who could write stock off
 * would be able to cover a shortage by declaring it damaged, which is exactly
 * the thing these reason codes exist to expose.
 *
 * Nothing here touches the ledger until it is posted, and posting is a
 * one-way door — see App\Services\StockAdjustmentService.
 */
class StockAdjustmentController extends Controller
{
    public function __construct(private readonly StockAdjustmentService $adjustments) {}

    public function index(Request $request): View
    {
        Gate::authorize('supervise');

        return view('stock.adjustments.index', [
            'adjustments' => $this->filtered($request),
            'reasons' => AdjustmentReason::options(),
            'statuses' => AdjustmentStatus::options(),
            'filters' => $request->only('reason', 'status'),
            'drafts' => StockAdjustment::where('status', AdjustmentStatus::Draft)->count(),
        ]);
    }

    public function create(Request $request): View
    {
        Gate::authorize('supervise');

        $adjustment = new StockAdjustment([
            'reason' => AdjustmentReason::tryFrom((string) $request->query('reason')) ?? AdjustmentReason::Recount,
            'adjusted_at' => now(),
        ]);

        $rows = $this->rowsFromOldInput()
            ?? $this->rowsForProduct($request->query('product'));

        return view('stock.adjustments.create', $this->formData($adjustment, $rows));
    }

    public function store(StoreStockAdjustmentRequest $request): RedirectResponse
    {
        $adjustment = DB::transaction(function () use ($request): StockAdjustment {
            $adjustment = StockAdjustment::create($request->adjustmentAttributes() + [
                'status' => AdjustmentStatus::Draft,
                'user_id' => $request->user()->getKey(),
            ]);

            $this->syncItems($adjustment, $request->items());

            return $adjustment;
        });

        ActivityLog::record('stock.adjustment.created', $adjustment, after: [
            'reference' => $adjustment->reference,
            'reason' => $adjustment->reason->value,
            'lines' => $adjustment->items()->count(),
        ]);

        if ($request->input('action') === 'post') {
            return $this->post($adjustment);
        }

        return redirect()
            ->route('stock.adjustments.show', $adjustment)
            ->with('status', __(':reference is saved but not posted. Stock has not changed yet.', [
                'reference' => $adjustment->reference,
            ]));
    }

    public function show(StockAdjustment $adjustment): View
    {
        Gate::authorize('supervise');

        $adjustment->load([
            'items.product.baseUnit',
            'items.product.productUnits.unit',
            'items.productUnit.unit',
            'user',
            'approver',
            'movements',
        ]);

        return view('stock.adjustments.show', [
            'adjustment' => $adjustment,
        ]);
    }

    public function edit(StockAdjustment $adjustment): View
    {
        Gate::authorize('supervise');

        $this->refuseUnlessDraft($adjustment);

        $adjustment->load(['items.product.baseUnit', 'items.product.productUnits.unit', 'items.productUnit']);

        return view('stock.adjustments.edit', $this->formData(
            $adjustment,
            $this->rowsFromOldInput() ?? $this->rowsFrom($adjustment),
        ));
    }

    public function update(StoreStockAdjustmentRequest $request, StockAdjustment $adjustment): RedirectResponse
    {
        Gate::authorize('supervise');

        $this->refuseUnlessDraft($adjustment);

        DB::transaction(function () use ($request, $adjustment): void {
            $adjustment->update($request->adjustmentAttributes());

            $adjustment->items()->delete();

            $this->syncItems($adjustment, $request->items());
        });

        ActivityLog::record('stock.adjustment.updated', $adjustment);

        if ($request->input('action') === 'post') {
            return $this->post($adjustment);
        }

        return redirect()
            ->route('stock.adjustments.show', $adjustment)
            ->with('status', __(':reference was saved.', ['reference' => $adjustment->reference]));
    }

    /**
     * Write the correction to the ledger. From here it can only be undone by
     * another correction, which is the point.
     */
    public function post(StockAdjustment $adjustment): RedirectResponse
    {
        Gate::authorize('supervise');

        try {
            $this->adjustments->post($adjustment, request()->user());
        } catch (RuntimeException $exception) {
            return back()->withErrors(['adjustment' => $exception->getMessage()]);
        }

        ActivityLog::record('stock.adjustment.posted', $adjustment, after: [
            'reference' => $adjustment->reference,
            'value_paisa' => $adjustment->value_paisa,
        ]);

        return redirect()
            ->route('stock.adjustments.show', $adjustment)
            ->with('status', __(':reference is posted. Stock has been corrected.', [
                'reference' => $adjustment->reference,
            ]));
    }

    /**
     * Abandon a draft. Posted corrections are never removed — the ledger row
     * they wrote has to stay explainable.
     */
    public function destroy(StockAdjustment $adjustment): RedirectResponse
    {
        Gate::authorize('supervise');

        try {
            $this->adjustments->cancel($adjustment);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['adjustment' => $exception->getMessage()]);
        }

        ActivityLog::record('stock.adjustment.cancelled', $adjustment);

        return redirect()
            ->route('stock.adjustments.index')
            ->with('status', __(':reference was cancelled. Nothing was changed.', [
                'reference' => $adjustment->reference,
            ]));
    }

    /**
     * @return LengthAwarePaginator<int, StockAdjustment>
     */
    private function filtered(Request $request): LengthAwarePaginator
    {
        return StockAdjustment::query()
            ->with(['user', 'items'])
            ->withCount('items')
            ->when($request->filled('reason'), fn (Builder $query) => $query->where('reason', $request->query('reason')))
            ->when($request->filled('status'), fn (Builder $query) => $query->where('status', $request->query('status')))
            ->latestFirst()
            ->paginate(25)
            ->withQueryString();
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function syncItems(StockAdjustment $adjustment, array $items): void
    {
        $reason = $adjustment->reason;

        foreach ($items as $item) {
            $product = Product::with('productUnits')->find($item['product_id']);
            $factor = max(1, (int) ($product?->productUnits->firstWhere('id', $item['product_unit_id'])?->conversion_factor ?? 1));
            $entered = (int) $item['qty'] * $factor;

            /* An indicative figure only. The service settles it against the
               shelf again at post time, because a sale rung up in between
               would otherwise be counted twice. */
            $adjustment->items()->create($item + [
                'qty_base' => match (true) {
                    $reason->isRecount() => 0,
                    $reason->addsStock() => $entered,
                    default => -$entered,
                },
            ]);
        }
    }

    /**
     * Everything the create and edit forms need.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function formData(StockAdjustment $adjustment, array $rows): array
    {
        return [
            'adjustment' => $adjustment,
            'rows' => $rows,
            'reasons' => collect(AdjustmentReason::cases())
                ->map(fn (AdjustmentReason $reason): array => [
                    'value' => $reason->value,
                    'label' => $reason->label(),
                    'description' => $reason->description(),
                    'adds' => $reason->addsStock(),
                    'recount' => $reason->isRecount(),
                ])
                ->all(),
        ];
    }

    /**
     * The rows a saved draft is holding, in the shape the Alpine form reads.
     *
     * @return array<int, array<string, mixed>>
     */
    private function rowsFrom(StockAdjustment $adjustment): array
    {
        return $adjustment->items
            ->map(fn ($item): array => static::row($item->product, $item->product_unit_id, [
                'qty' => (string) $item->qty,
                'unit_cost' => $item->unit_cost_base_paisa > 0
                    ? number_format($item->unit_cost_base_paisa * max(1, (int) ($item->productUnit?->conversion_factor ?? 1)) / 100, 2, '.', '')
                    : '',
                'note' => $item->note ?? '',
            ]))
            ->all();
    }

    /**
     * A single row, when the form was opened from one product's ledger.
     *
     * @return array<int, array<string, mixed>>
     */
    private function rowsForProduct(int|string|null $productId): array
    {
        if (blank($productId)) {
            return [];
        }

        $product = Product::with(['baseUnit', 'productUnits.unit'])->find($productId);

        return $product ? [static::row($product)] : [];
    }

    /**
     * After a validation failure the form must come back with what was typed,
     * including the item names — which only the database knows.
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

                return $product ? static::row($product, $item['product_unit_id'] ?? null, [
                    'qty' => (string) ($item['qty'] ?? ''),
                    'unit_cost' => (string) ($item['unit_cost'] ?? ''),
                    'note' => (string) ($item['note'] ?? ''),
                ]) : null;
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * One line of the form: the item, the sizes it can be counted in, what is
     * on the shelf right now, and whatever was typed against it.
     *
     * Also used by StockController::lookup(), so a scanned row and a restored
     * row are the same shape.
     *
     * @param  array<string, string>  $typed
     * @return array<string, mixed>
     */
    public static function row(Product $product, int|string|null $productUnitId = null, array $typed = []): array
    {
        $units = $product->productUnits
            ->sortBy('conversion_factor')
            ->values()
            ->map(fn ($level): array => [
                'id' => $level->id,
                'label' => $level->label($product->baseUnit?->name),
                'factor' => (int) $level->conversion_factor,
            ])
            ->all();

        return [
            'product_id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'units' => $units,
            'product_unit_id' => $productUnitId !== null
                ? (int) $productUnitId
                : ($product->baseProductUnit()?->id ?? ($units[0]['id'] ?? null)),
            'qty' => $typed['qty'] ?? '',
            'unit_cost' => $typed['unit_cost'] ?? '',
            'note' => $typed['note'] ?? '',
            'stock_base' => (int) $product->stock_qty_base,
            'stock_words' => Packaging::describe($product->stock_qty_base, $product->productUnits),
        ];
    }

    /**
     * @throws HttpException
     */
    private function refuseUnlessDraft(StockAdjustment $adjustment): void
    {
        abort_unless($adjustment->isEditable(), 403, __('This correction has already been dealt with. Correct it with another one.'));
    }
}
