<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Models\ActivityLog;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Unit;
use App\Services\PackagingService;
use App\Services\ScanService;
use App\Services\StarterCatalogueService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;

/**
 * The catalogue.
 *
 * Reading it is open to everyone on the till — a cashier checking a price is
 * the commonest use of this screen. Changing it needs a supervisor, because a
 * wrong conversion factor silently corrupts every sale of that item.
 */
class ProductController extends Controller
{
    public function __construct(private readonly PackagingService $packaging) {}

    public function index(Request $request, StarterCatalogueService $starter): View
    {
        return view('products.index', [
            'starterSeeded' => $starter->isInstalled(),
            'starterCount' => count($starter->products()),
            'products' => $this->filtered($request),
            'categories' => $this->categoryOptions(),
            'brands' => Brand::orderBy('name')->pluck('name', 'id'),
            'filters' => $request->only('q', 'category', 'brand', 'status'),
            'counts' => [
                'all' => Product::count(),
                'low' => Product::active()->lowStock()->count(),
            ],
        ]);
    }

    public function create(): View
    {
        Gate::authorize('supervise');

        return view('products.create', $this->formData(new Product([
            'tax_rate' => config('supermart.settings.tax.gst_rate.default'),
            'is_active' => true,
        ])));
    }

    public function store(StoreProductRequest $request): RedirectResponse
    {
        $product = Product::create($request->productAttributes());

        $this->packaging->sync($product, $request->levels());

        ActivityLog::record('product.created', $product, after: $product->only('sku', 'name'));

        return redirect()
            ->route('products.edit', $product)
            ->with('status', __(':name was added. Check the packaging below before you sell it.', [
                'name' => $product->name,
            ]));
    }

    public function edit(Product $product): View
    {
        Gate::authorize('supervise');

        $product->load(['productUnits.unit', 'productUnits.barcodes', 'baseUnit']);

        return view('products.edit', $this->formData($product));
    }

    public function update(UpdateProductRequest $request, Product $product): RedirectResponse
    {
        $before = $product->only('name', 'sku', 'is_active');

        $product->update($request->productAttributes());

        $this->packaging->sync($product, $request->levels());

        ActivityLog::record('product.updated', $product, $before, $product->only('name', 'sku', 'is_active'));

        return redirect()
            ->route('products.index')
            ->with('status', __(':name was saved.', ['name' => $product->name]));
    }

    /**
     * Hide, never delete. A product that has ever been sold has to stay
     * readable from old receipts and reports.
     */
    public function destroy(Product $product): RedirectResponse
    {
        Gate::authorize('supervise');

        $product->update(['is_active' => false]);

        ActivityLog::record('product.hidden', $product);

        return redirect()
            ->route('products.index')
            ->with('status', __(':name is hidden from the till. Its history is kept.', [
                'name' => $product->name,
            ]));
    }

    /**
     * What a scanned code means: which item, which packaging level, what
     * price. This is the price-check gun, and the same answer the till will
     * use in phase 4.
     */
    public function lookup(Request $request, ScanService $scanner): JsonResponse
    {
        $productUnit = $scanner->resolve((string) $request->query('code', ''));

        if (! $productUnit) {
            return response()->json(['found' => false]);
        }

        $product = $productUnit->product;

        return response()->json([
            'found' => true,
            'product' => [
                'name' => $product->name,
                'sku' => $product->sku,
                'url' => Gate::allows('supervise') ? route('products.edit', $product) : null,
            ],
            'unit' => [
                'label' => $productUnit->label($product->baseUnit?->name),
                'contains' => $productUnit->conversion_factor,
                'price' => $productUnit->formattedPrice(),
                'price_paisa' => $productUnit->sale_price_paisa,
            ],
        ]);
    }

    /**
     * @return LengthAwarePaginator<int, Product>
     */
    private function filtered(Request $request): LengthAwarePaginator
    {
        return Product::query()
            ->with(['category', 'brand', 'baseUnit', 'productUnits.unit'])
            ->search($request->query('q'))
            ->when($request->filled('category'), fn ($query) => $query->where('category_id', $request->query('category')))
            ->when($request->filled('brand'), fn ($query) => $query->where('brand_id', $request->query('brand')))
            ->when($request->query('status') === 'low', fn ($query) => $query->active()->lowStock())
            ->when($request->query('status') === 'hidden', fn ($query) => $query->where('is_active', false))
            ->when($request->query('status') !== 'hidden', fn ($query) => $query->orderByDesc('is_active'))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(25)
            ->withQueryString();
    }

    /**
     * Everything both the create and edit forms need, including the packaging
     * rows in the shape the Alpine builder reads.
     *
     * @return array<string, mixed>
     */
    private function formData(Product $product): array
    {
        return [
            'product' => $product,
            'categories' => $this->categoryOptions(),
            'brands' => Brand::active()->orderBy('name')->pluck('name', 'id'),
            'units' => Unit::orderBy('name')->get(['id', 'name', 'short_name', 'type']),
            'levels' => $this->levelPayload($product),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function levelPayload(Product $product): array
    {
        if (! $product->exists) {
            return [];
        }

        return $product->productUnits
            ->sortBy('conversion_factor')
            ->values()
            ->map(fn ($level): array => [
                'unit_id' => $level->unit_id,
                'parent_unit_id' => $level->parent_unit_id,
                'qty_per_parent' => $level->qty_per_parent,
                'sale_price' => number_format($level->sale_price_paisa / 100, 2, '.', ''),
                'mrp' => $level->mrp_paisa === null ? '' : number_format($level->mrp_paisa / 100, 2, '.', ''),
                'is_base' => $level->is_base,
                'is_default_sale' => $level->is_default_sale,
                'is_default_purchase' => $level->is_default_purchase,
                'barcodes' => $level->barcodes->pluck('code')->all(),
            ])
            ->all();
    }

    /**
     * Categories as "Parent / Child", so a flat select still reads as a tree.
     *
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
