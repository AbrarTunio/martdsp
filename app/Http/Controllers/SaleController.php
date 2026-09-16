<?php

namespace App\Http\Controllers;

use App\Enums\SaleStatus;
use App\Models\Printer;
use App\Models\Register;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * Bills already rung up: the day's list, one bill in full, and its receipt.
 *
 * A cashier sees only their own bills — enough to reprint a receipt for a
 * customer who comes back for one. A supervisor sees the shop's.
 */
class SaleController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $date = $this->date($request->query('date'));

        $base = Sale::query()
            ->rungUp()
            ->visibleTo($user)
            ->whereBetween('sold_at', [$date->copy()->startOfDay(), $date->copy()->endOfDay()]);

        $filtered = (clone $base)
            ->with(['customer', 'register', 'user'])
            ->withCount('items')
            ->when($request->filled('status'), fn (Builder $query) => $query->where('status', $request->query('status')))
            ->when($request->filled('register'), fn (Builder $query) => $query->where('register_id', $request->integer('register')))
            ->when($user->supervises() && $request->filled('cashier'), fn (Builder $query) => $query->where('user_id', $request->integer('cashier')))
            ->when($request->filled('q'), function (Builder $query) use ($request): void {
                $term = trim((string) $request->query('q'));
                $number = (int) preg_replace('/\D/', '', $term);

                $query->where(function (Builder $query) use ($term, $number): void {
                    if ($number > 0) {
                        $query->orWhere('invoice_no', $number);
                    }

                    $query->orWhereHas('customer', fn (Builder $customer) => $customer->search($term));
                });
            })
            ->latestFirst()
            ->paginate(30)
            ->withQueryString();

        $completed = (clone $base)->completed();

        return view('sales.index', [
            'sales' => $filtered,
            'date' => $date,
            'filters' => $request->only('status', 'register', 'cashier', 'q'),
            'statuses' => collect(SaleStatus::options())->except(SaleStatus::Held->value)->all(),
            'registers' => Register::query()->orderBy('name')->pluck('name', 'id'),
            'cashiers' => $user->supervises() ? User::query()->orderBy('name')->pluck('name', 'id') : collect(),
            'summary' => [
                'bills' => (clone $completed)->count(),
                'total_paisa' => (int) (clone $completed)->sum('total_paisa'),
                'discount_paisa' => (int) (clone $completed)->sum('discount_paisa'),
                'khata_paisa' => (int) (clone $completed)->sum('due_paisa'),
                'voided' => (clone $base)->where('status', SaleStatus::Void)->count(),
            ],
        ]);
    }

    public function show(Sale $sale): View
    {
        Gate::authorize('view-sale', $sale);

        abort_if($sale->status === SaleStatus::Held, 404);

        $sale->load([
            'items.productUnit.unit',
            'items.returnItems',
            'payments',
            'customer',
            'register',
            'user',
            'voider',
            'returns',
        ]);

        return view('sales.show', [
            'sale' => $sale,
            'counterPrinter' => Printer::forRegister($sale->register),
        ]);
    }

    /**
     * The receipt on its own page, sized for the paper, so the browser's
     * print dialog sends exactly the receipt to the USB thermal printer. With
     * ?print=1 it prints itself as soon as it loads.
     */
    public function receipt(Request $request, Sale $sale): View
    {
        Gate::authorize('view-sale', $sale);

        abort_if($sale->status === SaleStatus::Held, 404);

        $sale->load(['items', 'payments', 'customer', 'register', 'user']);

        $paper = (string) $request->query('paper', $sale->register?->paper() ?? Setting::read('receipt.paper_width'));

        return view('sales.receipt', [
            'sale' => $sale,
            'paper' => in_array($paper, Register::PAPERS, true) ? $paper : '80',
            'autoPrint' => $request->boolean('print'),
            'shop' => [
                'name' => (string) Setting::read('shop.name'),
                'address' => (string) Setting::read('shop.address'),
                'phone' => (string) Setting::read('shop.phone'),
                'ntn' => (string) Setting::read('shop.ntn'),
                'strn' => (string) Setting::read('shop.strn'),
                'footer' => (string) Setting::read('receipt.footer_note'),
            ],
        ]);
    }

    private function date(mixed $value): Carbon
    {
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            $date = Carbon::createFromFormat('Y-m-d', $value);

            if ($date !== false && $date->lte(today())) {
                return $date->startOfDay();
            }
        }

        return today();
    }
}
