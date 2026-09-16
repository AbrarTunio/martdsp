<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSupplierRequest;
use App\Models\ActivityLog;
use App\Models\Supplier;
use App\Services\SupplierLedgerService;
use App\Support\PurchaseRows;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Adds a supplier while the van is still at the door.
 *
 * The first delivery a shop enters is usually from a supplier nobody has had
 * a chance to type in yet, and sending the shopkeeper off to another screen
 * loses the half-scanned bill. So the same rules the full supplier form uses
 * are applied here, and the answer is the new supplier in the shape the
 * delivery form's picker reads.
 */
class QuickSupplierController extends Controller
{
    public function __construct(private readonly SupplierLedgerService $ledger) {}

    public function store(StoreSupplierRequest $request): JsonResponse
    {
        $supplier = DB::transaction(function () use ($request): Supplier {
            $supplier = Supplier::create($request->supplierAttributes());

            $this->ledger->opening($supplier, $request->openingBalancePaisa());

            return $supplier;
        });

        ActivityLog::record('supplier.created', $supplier, after: $supplier->only('name', 'phone') + ['via' => 'purchase']);

        return response()->json([
            'message' => __(':name was added.', ['name' => $supplier->name]),
            'supplier' => PurchaseRows::supplier($supplier->refresh()),
        ], 201);
    }
}
