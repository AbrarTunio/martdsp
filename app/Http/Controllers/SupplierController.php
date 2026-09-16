<?php

namespace App\Http\Controllers;

use App\Enums\PaymentMethod;
use App\Enums\PurchaseStatus;
use App\Enums\SupplierEntryType;
use App\Http\Requests\StoreSupplierRequest;
use App\Http\Requests\UpdateSupplierRequest;
use App\Models\ActivityLog;
use App\Models\Supplier;
use App\Services\SupplierLedgerService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * The people the shop buys from, and what it owes each of them.
 *
 * Supervisors only. What the shop pays for its stock is the most sensitive
 * number in the building after the takings.
 */
class SupplierController extends Controller
{
    public function __construct(private readonly SupplierLedgerService $ledger) {}

    public function index(Request $request): View
    {
        Gate::authorize('supervise');

        return view('suppliers.index', [
            'suppliers' => $this->filtered($request),
            'filters' => $request->only('q', 'view'),
            'summary' => [
                'owed_paisa' => (int) Supplier::where('balance_paisa', '>', 0)->sum('balance_paisa'),
                'owed_count' => Supplier::owed()->count(),
                'advance_paisa' => (int) -Supplier::where('balance_paisa', '<', 0)->sum('balance_paisa'),
                'active' => Supplier::active()->count(),
            ],
        ]);
    }

    public function create(): View
    {
        Gate::authorize('supervise');

        return view('suppliers.create', [
            'supplier' => new Supplier(['is_active' => true, 'payment_terms_days' => 0]),
        ]);
    }

    public function store(StoreSupplierRequest $request): RedirectResponse
    {
        $supplier = DB::transaction(function () use ($request): Supplier {
            $supplier = Supplier::create($request->supplierAttributes());

            $this->ledger->opening($supplier, $request->openingBalancePaisa());

            return $supplier;
        });

        ActivityLog::record('supplier.created', $supplier, after: [
            'name' => $supplier->name,
            'opening_balance_paisa' => $supplier->opening_balance_paisa,
        ]);

        return redirect()
            ->route('suppliers.show', $supplier)
            ->with('status', __(':name was added.', ['name' => $supplier->name]));
    }

    /**
     * The supplier's statement, newest line first, the way the salesman's own
     * book reads — so the two can be compared line by line when they disagree.
     */
    public function show(Supplier $supplier): View
    {
        Gate::authorize('supervise');

        $entries = $supplier->ledgerEntries()
            ->with(['user', 'reference'])
            ->latestFirst()
            ->paginate(30);

        $lastPayment = $supplier->ledgerEntries()
            ->where('type', SupplierEntryType::Payment)
            ->latestFirst()
            ->first();

        return view('suppliers.show', [
            'supplier' => $supplier,
            'entries' => $entries,
            'purchases' => $supplier->purchases()->with('items')->latestFirst()->limit(5)->get(),
            'stats' => [
                'overdue_paisa' => $supplier->overduePaisa(),
                'bought_this_month_paisa' => (int) $supplier->purchases()
                    ->where('status', PurchaseStatus::Received)
                    ->whereBetween('purchase_date', [today()->startOfMonth(), today()])
                    ->sum('total_paisa'),
                'drafts' => $supplier->purchases()->where('status', PurchaseStatus::Draft)->count(),
                'last_payment' => $lastPayment,
            ],
            'methods' => PaymentMethod::options(),
        ]);
    }

    public function edit(Supplier $supplier): View
    {
        Gate::authorize('supervise');

        return view('suppliers.edit', [
            'supplier' => $supplier,
        ]);
    }

    public function update(UpdateSupplierRequest $request, Supplier $supplier): RedirectResponse
    {
        $before = $supplier->only('name', 'company', 'phone', 'payment_terms_days', 'is_active');

        $supplier->update($request->supplierAttributes());

        ActivityLog::record('supplier.updated', $supplier, $before, $supplier->only('name', 'company', 'phone', 'payment_terms_days', 'is_active'));

        return redirect()
            ->route('suppliers.show', $supplier)
            ->with('status', __(':name was saved.', ['name' => $supplier->name]));
    }

    /**
     * Hide, never delete. Their bills and payments have to stay readable, and
     * an account with money on it cannot simply disappear.
     */
    public function destroy(Supplier $supplier): RedirectResponse
    {
        Gate::authorize('supervise');

        $supplier->update(['is_active' => false]);

        ActivityLog::record('supplier.hidden', $supplier);

        return redirect()
            ->route('suppliers.index')
            ->with('status', __(':name is hidden from new purchases. Their account is kept.', [
                'name' => $supplier->name,
            ]));
    }

    /**
     * @return LengthAwarePaginator<int, Supplier>
     */
    private function filtered(Request $request): LengthAwarePaginator
    {
        $view = (string) $request->query('view', 'active');

        return Supplier::query()
            ->search($request->query('q'))
            ->when($view === 'active', fn (Builder $query) => $query->active())
            ->when($view === 'owed', fn (Builder $query) => $query->owed())
            ->when($view === 'hidden', fn (Builder $query) => $query->where('is_active', false))
            ->when($view === 'owed', fn (Builder $query) => $query->orderByDesc('balance_paisa'))
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();
    }
}
