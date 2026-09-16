<?php

namespace App\Http\Controllers;

use App\Enums\StockTakeStatus;
use App\Http\Requests\StoreStockTakeRequest;
use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Product;
use App\Models\StockTake;
use App\Services\StockTakeService;
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
 * Stock takes: walking a section of the shop with the scanner and posting
 * what was really there.
 *
 * Supervisor only, for the same reason as corrections — a count that could
 * be posted by whoever was short would hide the shortage.
 *
 * A count can be saved and picked up again as many times as it takes to get
 * round the shelves. Nothing reaches the books until it is posted; see
 * App\Services\StockTakeService for how the difference is worked out.
 */
class StockTakeController extends Controller
{
    public function __construct(private readonly StockTakeService $takes) {}

    public function index(Request $request): View
    {
        Gate::authorize('supervise');

        return view('stock.takes.index', [
            'takes' => $this->filtered($request),
            'statuses' => StockTakeStatus::options(),
            'filters' => $request->only('status'),
            'open' => StockTake::where('status', StockTakeStatus::Draft)->count(),
        ]);
    }

    public function create(Request $request): View
    {
        Gate::authorize('supervise');

        $take = new StockTake([
            'category_id' => $request->integer('category') ?: null,
            'started_at' => now(),
        ]);

        return view('stock.takes.create', $this->formData($take, $this->rowsFromOldInput() ?? []));
    }

    public function store(StoreStockTakeRequest $request): RedirectResponse
    {
        $take = DB::transaction(function () use ($request): StockTake {
            /* Scanning starts before the first save, so a new count reaches
               back as far as its first scan — within reason. */
            $items = $request->items(notBefore: now()->subDay());

            $take = StockTake::create($request->takeAttributes() + [
                'status' => StockTakeStatus::Draft,
                'user_id' => $request->user()->getKey(),
                'started_at' => collect($items)->pluck('counted_at')->push(now())->min(),
            ]);

            $take->items()->createMany($items);

            return $take;
        });

        ActivityLog::record('stock.take.created', $take, after: [
            'reference' => $take->reference,
            'section' => $take->category_id,
            'lines' => $take->items()->count(),
        ]);

        if ($request->input('action') === 'post') {
            return $this->post($take);
        }

        return redirect()
            ->route('stock.takes.show', $take)
            ->with('status', __(':reference is saved. Carry on counting whenever you are ready — stock has not changed yet.', [
                'reference' => $take->reference,
            ]));
    }

    public function show(StockTake $take): View
    {
        Gate::authorize('supervise');

        $take->load([
            'items.product.baseUnit',
            'items.product.productUnits.unit',
            'items.productUnit.unit',
            'category.parent',
            'user',
            'poster',
        ]);

        $items = $take->items->sortBy(fn ($item): string => $item->product?->name ?? '')->values();

        return view('stock.takes.show', [
            'take' => $take,
            'short' => $items->filter->isShort()->sortBy('variance_value_paisa')->values(),
            'over' => $items->filter->isOver()->sortByDesc('variance_value_paisa')->values(),
            'matched' => $items->reject(fn ($item): bool => $item->isShort() || $item->isOver())->values(),
            'items' => $items,
        ]);
    }

    public function edit(StockTake $take): View
    {
        Gate::authorize('supervise');

        $this->refuseUnlessDraft($take);

        $take->load(['items.product.baseUnit', 'items.product.productUnits.unit', 'items.productUnit']);

        return view('stock.takes.edit', $this->formData(
            $take,
            $this->rowsFromOldInput() ?? $this->rowsFrom($take),
        ));
    }

    public function update(StoreStockTakeRequest $request, StockTake $take): RedirectResponse
    {
        Gate::authorize('supervise');

        $this->refuseUnlessDraft($take);

        DB::transaction(function () use ($request, $take): void {
            $take->update($request->takeAttributes());

            $take->items()->delete();

            $take->items()->createMany($request->items(notBefore: $take->started_at));
        });

        ActivityLog::record('stock.take.updated', $take, after: [
            'lines' => $take->items()->count(),
        ]);

        if ($request->input('action') === 'post') {
            return $this->post($take);
        }

        return redirect()
            ->route('stock.takes.show', $take)
            ->with('status', __(':reference was saved.', ['reference' => $take->reference]));
    }

    /**
     * Settle the count against the books. One-way: a count that turns out
     * wrong is corrected by counting again.
     */
    public function post(StockTake $take): RedirectResponse
    {
        Gate::authorize('supervise');

        try {
            $this->takes->post($take, request()->user());
        } catch (RuntimeException $exception) {
            return back()->withErrors(['take' => $exception->getMessage()]);
        }

        ActivityLog::record('stock.take.posted', $take, after: [
            'reference' => $take->reference,
            'lines' => $take->items()->count(),
            'variance_value_paisa' => $take->variance_value_paisa,
        ]);

        return redirect()
            ->route('stock.takes.show', $take)
            ->with('status', __(':reference is posted. Stock now matches what was counted.', [
                'reference' => $take->reference,
            ]));
    }

    /**
     * Abandon a count still in progress. A posted one stays: the stock it
     * moved has to remain explainable.
     */
    public function destroy(StockTake $take): RedirectResponse
    {
        Gate::authorize('supervise');

        try {
            $this->takes->cancel($take);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['take' => $exception->getMessage()]);
        }

        ActivityLog::record('stock.take.cancelled', $take);

        return redirect()
            ->route('stock.takes.index')
            ->with('status', __(':reference was abandoned. Nothing was changed.', [
                'reference' => $take->reference,
            ]));
    }

    /**
     * @return LengthAwarePaginator<int, StockTake>
     */
    private function filtered(Request $request): LengthAwarePaginator
    {
        return StockTake::query()
            ->with(['user', 'category.parent'])
            ->withCount('items')
            ->when($request->filled('status'), fn (Builder $query) => $query->where('status', $request->query('status')))
            ->latestFirst()
            ->paginate(25)
            ->withQueryString();
    }

    /**
     * Everything the create and edit forms need.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function formData(StockTake $take, array $rows): array
    {
        return [
            'take' => $take,
            'rows' => $rows,
            'categories' => Category::with('parent')
                ->orderBy('name')
                ->get()
                ->sortBy(fn (Category $category): string => $category->fullName())
                ->mapWithKeys(fn (Category $category): array => [$category->id => $category->fullName()])
                ->all(),
        ];
    }

    /**
     * The lines a saved count is holding, in the shape the Alpine form reads.
     *
     * @return array<int, array<string, mixed>>
     */
    private function rowsFrom(StockTake $take): array
    {
        return $take->items
            ->map(fn ($item): array => StockAdjustmentController::row($item->product, $item->product_unit_id, [
                'qty' => (string) $item->counted_qty,
                'note' => $item->note ?? '',
            ]) + ['counted_at' => $item->counted_at?->toIso8601String()])
            ->all();
    }

    /**
     * After a validation failure the sheet comes back with what was counted,
     * so a hundred scans are not lost to one mistake.
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

                return $product ? StockAdjustmentController::row($product, $item['product_unit_id'] ?? null, [
                    'qty' => (string) ($item['qty'] ?? ''),
                    'note' => (string) ($item['note'] ?? ''),
                ]) + ['counted_at' => $item['counted_at'] ?? null] : null;
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @throws HttpException
     */
    private function refuseUnlessDraft(StockTake $take): void
    {
        abort_unless($take->isEditable(), 403, __('This count has already been dealt with. Count again to correct it.'));
    }
}
