<?php

namespace App\Http\Controllers;

use App\Enums\MovementType;
use App\Models\Category;
use App\Models\Product;
use App\Services\ScanService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * What is on the shelf, and how it got there.
 *
 * Reading stock is open to everyone on the till — a cashier being able to
 * answer "do we have any left?" without calling the owner is the point. Cost
 * and value are held back behind `see-financials`, because a cashier knowing
 * the margin on every item is a different conversation entirely.
 */
class StockController extends Controller
{
    public function index(Request $request): View
    {
        return view('stock.index', [
            'products' => $this->filtered($request),
            'categories' => $this->categoryOptions(),
            'filters' => $request->only('q', 'category', 'view'),
            'summary' => $this->summary(),
        ]);
    }

    /**
     * The ledger for one product: every movement, newest first, each carrying
     * the balance it left behind.
     */
    public function show(Request $request, Product $product): View
    {
        $product->load(['baseUnit', 'productUnits.unit', 'category', 'brand']);

        $movements = $product->movements()
            ->with(['productUnit.unit', 'user', 'reference'])
            ->when($request->filled('type'), fn (Builder $query) => $query->where('type', $request->query('type')))
            ->latestFirst()
            ->paginate(50)
            ->withQueryString();

        return view('stock.show', [
            'product' => $product,
            'movements' => $movements,
            'types' => MovementType::options(),
            'filters' => $request->only('type'),
        ]);
    }

    /**
     * What a scanned code — or a typed search — means, in the shape the
     * adjustment form's rows take. The answer carries what is on the shelf
     * right now, because a recount is only useful next to it.
     */
    public function lookup(Request $request, ScanService $scanner): JsonResponse
    {
        Gate::authorize('supervise');

        $code = trim((string) $request->query('code', ''));
        $productUnit = $scanner->resolve($code);
        $product = $productUnit?->product;

        if (! $product) {
            $product = $scanner->search($code, 1)->first();
        }

        if (! $product) {
            return response()->json(['found' => false]);
        }

        $product->loadMissing(['baseUnit', 'productUnits.unit']);

        return response()->json([
            'found' => true,
            'row' => StockAdjustmentController::row($product, $productUnit?->id),
            /* The shop's clock, not the phone's: a stock take measures each
               item against the books as they stood at this moment. */
            'scanned_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * @return LengthAwarePaginator<int, Product>
     */
    private function filtered(Request $request): LengthAwarePaginator
    {
        $view = (string) $request->query('view', 'all');

        return Product::query()
            ->with(['baseUnit', 'productUnits.unit'])
            ->search($request->query('q'))
            ->when($request->filled('category'), fn (Builder $query) => $query->where('category_id', $request->query('category')))
            ->when($view === 'low', fn (Builder $query) => $query->active()->lowStock())
            ->when($view === 'out', fn (Builder $query) => $query->active()->where('stock_qty_base', '<=', 0))
            ->when($view === 'negative', fn (Builder $query) => $query->where('stock_qty_base', '<', 0))
            ->when($view === 'all', fn (Builder $query) => $query->active())
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();
    }

    /**
     * The three numbers worth putting at the top of the screen. Value is only
     * computed for those allowed to see it.
     *
     * @return array<string, int|null>
     */
    private function summary(): array
    {
        return [
            'items' => Product::active()->count(),
            'low' => Product::active()->lowStock()->count(),
            'out' => Product::active()->where('stock_qty_base', '<=', 0)->count(),
            'negative' => Product::where('stock_qty_base', '<', 0)->count(),
            'value_paisa' => Gate::allows('see-financials')
                ? (int) Product::active()->sum(DB::raw('stock_qty_base * avg_cost_base_paisa'))
                : null,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function categoryOptions(): array
    {
        return Category::with('parent')
            ->orderBy('name')
            ->get()
            ->sortBy(fn (Category $category): string => $category->fullName())
            ->mapWithKeys(fn (Category $category): array => [$category->id => $category->fullName()])
            ->all();
    }
}
