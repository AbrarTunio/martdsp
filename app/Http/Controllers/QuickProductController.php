<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreQuickProductRequest;
use App\Models\ActivityLog;
use App\Models\Product;
use App\Models\Setting;
use App\Services\PackagingService;
use App\Support\PurchaseRows;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Adds a product the moment it turns up on a delivery, without leaving the
 * purchase being scanned. Answers with the new line for the purchase form.
 */
class QuickProductController extends Controller
{
    public function __construct(private readonly PackagingService $packaging) {}

    public function store(StoreQuickProductRequest $request): JsonResponse
    {
        $product = DB::transaction(function () use ($request): Product {
            $product = Product::create([
                'name' => $request->validated('name'),
                'category_id' => $request->validated('category_id'),
                'base_unit_id' => (int) $request->validated('base_unit_id'),
                'tax_rate' => Setting::read('tax.gst_rate'),
                'is_active' => true,
            ]);

            $this->packaging->sync($product, $request->levels());

            return $product;
        });

        ActivityLog::record('product.created', $product, after: $product->only('sku', 'name') + ['via' => 'purchase']);

        $product->refresh()->load(['baseUnit', 'productUnits.unit']);

        $scannedLevel = $product->productUnits->firstWhere('unit_id', $request->scannedUnitId());

        return response()->json([
            'found' => true,
            'row' => PurchaseRows::row($product, $scannedLevel?->id),
        ], 201);
    }
}
