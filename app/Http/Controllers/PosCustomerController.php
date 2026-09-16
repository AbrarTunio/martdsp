<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreQuickCustomerRequest;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Finding the customer at the till, and adding a new one without leaving it.
 */
class PosCustomerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $term = trim((string) $request->query('q', ''));

        $customers = Customer::query()
            ->active()
            ->search($term)
            ->orderByDesc('balance_paisa')
            ->orderBy('name')
            ->limit(12)
            ->get();

        return response()->json([
            'customers' => $customers->map(fn (Customer $customer): array => self::payload($customer))->all(),
        ]);
    }

    public function store(StoreQuickCustomerRequest $request): JsonResponse
    {
        $customer = Customer::query()->create($request->validated());

        ActivityLog::record('customer.created', $customer, after: $customer->only('name', 'phone'));

        return response()->json([
            'message' => __(':name now has a khata.', ['name' => $customer->name]),
            'customer' => self::payload($customer->refresh()),
        ], 201);
    }

    /**
     * @return array{id: int, name: string, phone: string|null, label: string, balance_paisa: int, balance: string, credit_limit_paisa: int}
     */
    public static function payload(Customer $customer): array
    {
        return [
            'id' => $customer->id,
            'name' => $customer->name,
            'phone' => $customer->phone,
            'label' => $customer->displayName(),
            'balance_paisa' => (int) $customer->balance_paisa,
            'balance' => Money::withSymbol((int) $customer->balance_paisa),
            'credit_limit_paisa' => (int) $customer->credit_limit_paisa,
        ];
    }
}
