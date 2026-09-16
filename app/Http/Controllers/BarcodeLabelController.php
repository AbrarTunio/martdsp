<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductUnit;
use App\Support\Code128;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Printed labels for items that arrive without a barcode — loose stock, a
 * repacked sack, anything the supplier ships bare.
 *
 * Every packaging level gets its own label, because a carton and the sachet
 * inside it are different prices and must scan differently.
 */
class BarcodeLabelController extends Controller
{
    /**
     * The label sizes on offer. Sheet labels are laid out in millimetres so
     * they line up with the die-cut stationery sold in the market.
     */
    private const SIZES = [
        'a4' => ['label' => 'A4 sheet (3 across)', 'width' => 63.5, 'height' => 38.1, 'columns' => 3],
        'thermal' => ['label' => 'Thermal roll (50 mm)', 'width' => 50.0, 'height' => 30.0, 'columns' => 1],
    ];

    public function create(Request $request): View
    {
        Gate::authorize('supervise');

        $product = $request->filled('product')
            ? Product::with(['productUnits.unit', 'productUnits.barcodes', 'baseUnit'])->find($request->query('product'))
            : null;

        return view('products.labels.create', [
            'product' => $product,
            'matches' => $this->matches($request),
            'query' => (string) $request->query('q', ''),
            'sizes' => array_map(fn (array $size): string => $size['label'], self::SIZES),
        ]);
    }

    /**
     * The sheet itself. A plain GET so the shopkeeper can reprint from the
     * browser's back button when a label jams.
     */
    public function sheet(Request $request): View
    {
        Gate::authorize('supervise');

        $validated = $request->validate([
            'copies' => ['required', 'array', 'min:1'],
            'copies.*' => ['nullable', 'integer', 'min:0', 'max:200'],
            'size' => ['required', 'string', 'in:'.implode(',', array_keys(self::SIZES))],
            'show_price' => ['boolean'],
        ]);

        $wanted = array_filter($validated['copies'], fn ($copies): bool => (int) $copies > 0);

        $units = ProductUnit::with(['unit', 'barcodes', 'product.baseUnit'])
            ->whereKey(array_keys($wanted))
            ->get();

        return view('products.labels.sheet', [
            'labels' => $this->expand($units, $wanted),
            'size' => self::SIZES[$validated['size']],
            'showPrice' => $request->boolean('show_price'),
        ]);
    }

    /**
     * One entry per label to be printed, already carrying the code that will
     * be drawn. A level with no barcode of its own falls back to the SKU, so
     * a freshly entered product can still be labelled.
     *
     * @param  Collection<int, ProductUnit>  $units
     * @param  array<int|string, mixed>  $wanted
     * @return array<int, array{code: string, name: string, unit: string, price: int, printable: bool}>
     */
    private function expand($units, array $wanted): array
    {
        $labels = [];

        foreach ($units as $unit) {
            $code = $unit->primaryBarcode()?->code ?? (string) $unit->product?->sku;

            $label = [
                'code' => $code,
                'name' => (string) $unit->product?->name,
                'unit' => $unit->label($unit->product?->baseUnit?->name),
                'price' => (int) $unit->sale_price_paisa,
                'printable' => Code128::isPrintable($code),
            ];

            foreach (range(1, (int) $wanted[$unit->getKey()]) as $ignored) {
                $labels[] = $label;
            }
        }

        return $labels;
    }

    /**
     * @return Collection<int, Product>|null
     */
    private function matches(Request $request)
    {
        if (! $request->filled('q')) {
            return null;
        }

        return Product::search($request->query('q'))
            ->with('baseUnit')
            ->orderBy('name')
            ->limit(20)
            ->get();
    }
}
