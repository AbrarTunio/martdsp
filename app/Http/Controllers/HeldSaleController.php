<?php

namespace App\Http\Controllers;

use App\Http\Requests\HoldSaleRequest;
use App\Models\Customer;
use App\Models\ProductUnit;
use App\Models\Sale;
use App\Services\SaleService;
use App\Support\Money;
use App\Support\PosItem;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Baskets parked while a customer runs back for something they forgot.
 *
 * Held baskets are shared across the shop: a customer who was started at one
 * counter can be finished at another.
 */
class HeldSaleController extends Controller
{
    public function __construct(private readonly SaleService $sales) {}

    public function index(): JsonResponse
    {
        $held = Sale::query()
            ->held()
            ->with(['customer', 'register', 'user'])
            ->withCount('items')
            ->latest('id')
            ->limit(50)
            ->get();

        return response()->json([
            'held' => $held->map(fn (Sale $sale): array => [
                'id' => $sale->id,
                'customer' => $sale->customerName(),
                'items' => $sale->items_count,
                'total' => Money::withSymbol($sale->total_paisa),
                'note' => $sale->note,
                'by' => $sale->user?->name,
                'register' => $sale->register?->name,
                'held_at' => $sale->created_at?->diffForHumans(),
            ])->all(),
        ]);
    }

    public function store(HoldSaleRequest $request): JsonResponse
    {
        try {
            $sale = $this->sales->hold(
                cashier: $request->user(),
                register: $request->register(),
                lines: $request->lines(),
                customer: $request->customer(),
                billDiscount: $request->billDiscount(),
                note: $request->note(),
            );
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => __('Basket put on hold. Serve the next customer.'),
            'held_count' => Sale::query()->held()->count(),
            'id' => $sale->id,
        ], 201);
    }

    /**
     * Take the basket back off hold. Lines whose item has since been deleted
     * or switched off are left out, and the till is told how many.
     */
    public function resume(Sale $sale): JsonResponse
    {
        try {
            $cart = $this->sales->resume($sale);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $units = ProductUnit::query()
            ->with(['unit', 'product.baseUnit', 'product.productUnits.unit'])
            ->whereIn('id', array_column($cart['lines'], 'product_unit_id'))
            ->get()
            ->keyBy('id');

        $lines = [];
        $dropped = 0;

        foreach ($cart['lines'] as $line) {
            $unit = $units->get($line['product_unit_id']);

            if (! $unit || ! $unit->product?->is_active) {
                $dropped++;

                continue;
            }

            $lines[] = [
                'item' => PosItem::fromProductUnit($unit),
                'qty' => $line['qty'],
                'discount' => $line['discount'],
            ];
        }

        $customer = $cart['customer_id'] ? Customer::query()->active()->find($cart['customer_id']) : null;

        return response()->json([
            'message' => $dropped > 0
                ? trans_choice('{1} Basket is back. One item is no longer on sale and was left out.|[2,*] Basket is back. :count items are no longer on sale and were left out.', $dropped)
                : __('Basket is back.'),
            'cart' => [
                'customer' => $customer ? PosCustomerController::payload($customer) : null,
                'bill_discount' => $cart['bill_discount'],
                'note' => $cart['note'],
                'lines' => $lines,
            ],
            'held_count' => Sale::query()->held()->count(),
        ]);
    }

    public function destroy(Sale $sale): JsonResponse
    {
        try {
            $this->sales->discard($sale);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => __('Held basket thrown away.'),
            'held_count' => Sale::query()->held()->count(),
        ]);
    }
}
