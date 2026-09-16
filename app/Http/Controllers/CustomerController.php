<?php

namespace App\Http\Controllers;

use App\Enums\CustomerEntryType;
use App\Enums\SaleStatus;
use App\Enums\TenderType;
use App\Http\Requests\StoreCustomerRequest;
use App\Http\Requests\UpdateCustomerRequest;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Register;
use App\Models\Setting;
use App\Services\KhataService;
use App\Support\KhataAging;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * The khata: who buys on credit, what they owe, and every line of how it got
 * there.
 *
 * Reading a khata is open to everyone on the till, because the answer to
 * "kitna baaqi hai?" is needed at the counter. Who gets a khata at all, and
 * how much credit they are allowed, is the owner's decision.
 */
class CustomerController extends Controller
{
    public function __construct(private readonly KhataService $khata) {}

    public function index(Request $request): View
    {
        return view('customers.index', [
            'customers' => $this->filtered($request),
            'filters' => $request->only('q', 'view'),
            'summary' => [
                'owed_paisa' => (int) Customer::where('balance_paisa', '>', 0)->sum('balance_paisa'),
                'owing_count' => Customer::owing()->count(),
                'advance_paisa' => (int) -Customer::where('balance_paisa', '<', 0)->sum('balance_paisa'),
                'active' => Customer::active()->count(),
            ],
        ]);
    }

    public function create(): View
    {
        Gate::authorize('supervise');

        return view('customers.create', [
            'customer' => new Customer(['is_active' => true, 'credit_limit_paisa' => 0]),
        ]);
    }

    public function store(StoreCustomerRequest $request): RedirectResponse
    {
        $customer = DB::transaction(function () use ($request): Customer {
            $customer = Customer::create($request->customerAttributes());

            $this->khata->opening($customer, $request->openingBalancePaisa());

            return $customer;
        });

        ActivityLog::record('customer.created', $customer, after: [
            'name' => $customer->name,
            'credit_limit_paisa' => $customer->credit_limit_paisa,
            'opening_balance_paisa' => $customer->opening_balance_paisa,
        ]);

        return redirect()
            ->route('customers.show', $customer)
            ->with('status', __(':name now has a khata.', ['name' => $customer->name]));
    }

    /**
     * One customer's khata, newest line first, the way the shopkeeper's own
     * register reads — so their page and this screen can be compared line by
     * line when the two disagree.
     */
    public function show(Customer $customer): View
    {
        $aging = KhataAging::for($customer);

        $lastPayment = $customer->ledgerEntries()
            ->where('type', CustomerEntryType::Payment)
            ->latestFirst()
            ->first();

        $registers = Register::query()->active()->with('openDrawer')->get();

        return view('customers.show', [
            'customer' => $customer,
            'aging' => $aging,
            'entries' => $customer->ledgerEntries()->with(['user', 'reference'])->latestFirst()->paginate(30),
            'sales' => $customer->sales()
                ->where('status', SaleStatus::Completed)
                ->latest('sold_at')
                ->limit(5)
                ->get(),
            'stats' => [
                'bought_this_month_paisa' => (int) $customer->sales()
                    ->where('status', SaleStatus::Completed)
                    ->whereBetween('sold_at', [today()->startOfMonth(), now()])
                    ->sum('total_paisa'),
                'last_payment' => $lastPayment,
            ],
            'methods' => TenderType::paymentOptions(),
            'registers' => $registers,
            'defaultRegister' => $registers->first(fn (Register $register): bool => $register->openDrawer !== null),
            'creditDays' => (int) Setting::read('khata.credit_days'),
        ]);
    }

    public function edit(Customer $customer): View
    {
        Gate::authorize('supervise');

        return view('customers.edit', [
            'customer' => $customer,
        ]);
    }

    public function update(UpdateCustomerRequest $request, Customer $customer): RedirectResponse
    {
        $watched = ['name', 'phone', 'credit_limit_paisa', 'is_active'];
        $before = $customer->only($watched);

        $customer->update($request->customerAttributes());

        ActivityLog::record('customer.updated', $customer, $before, $customer->only($watched));

        return redirect()
            ->route('customers.show', $customer)
            ->with('status', __(':name was saved.', ['name' => $customer->name]));
    }

    /**
     * Hide, never delete. Every line of a khata has to stay readable, and an
     * account with money on it cannot simply disappear.
     */
    public function destroy(Customer $customer): RedirectResponse
    {
        Gate::authorize('supervise');

        $customer->update(['is_active' => false]);

        ActivityLog::record('customer.hidden', $customer);

        return redirect()
            ->route('customers.index')
            ->with('status', __(':name is hidden from the till. Their khata is kept.', [
                'name' => $customer->name,
            ]));
    }

    /**
     * @return LengthAwarePaginator<int, Customer>
     */
    private function filtered(Request $request): LengthAwarePaginator
    {
        $view = (string) $request->query('view', 'active');

        return Customer::query()
            ->search($request->query('q'))
            ->when($view === 'active', fn (Builder $query) => $query->active())
            ->when($view === 'owing', fn (Builder $query) => $query->owing()->orderByDesc('balance_paisa'))
            ->when($view === 'hidden', fn (Builder $query) => $query->where('is_active', false))
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();
    }
}
