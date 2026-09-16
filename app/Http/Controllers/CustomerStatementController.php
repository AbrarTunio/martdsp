<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Register;
use App\Models\Setting;
use App\Support\KhataAging;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * A customer's khata on paper — the one thing that settles an argument at the
 * counter.
 *
 * It prints on either thermal roll for a quick "yeh raha hisaab", or on A4 for
 * a customer who wants something to take to their own accountant. A date range
 * brings forward whatever was owed before it, so a part of the khata still
 * adds up on its own.
 */
class CustomerStatementController extends Controller
{
    public function __invoke(Request $request, Customer $customer): View
    {
        $from = $this->date($request->query('from'));
        $to = $this->date($request->query('to'));

        $entries = $customer->ledgerEntries()
            ->with('user')
            ->when($from, fn ($query) => $query->whereDate('entry_date', '>=', $from))
            ->when($to, fn ($query) => $query->whereDate('entry_date', '<=', $to))
            ->inLedgerOrder()
            ->get();

        $broughtForward = $from
            ? (int) $customer->ledgerEntries()
                ->whereDate('entry_date', '<', $from)
                ->sum(DB::raw('debit_paisa - credit_paisa'))
            : 0;

        $paper = (string) $request->query('paper', Setting::read('receipt.paper_width'));

        return view('customers.statement', [
            'customer' => $customer,
            'entries' => $entries,
            'broughtForward' => $broughtForward,
            'from' => $from,
            'to' => $to,
            'aging' => KhataAging::for($customer),
            'paper' => in_array($paper, Register::PAPERS, true) ? $paper : '80',
            'autoPrint' => $request->boolean('print'),
            'shop' => [
                'name' => (string) Setting::read('shop.name'),
                'address' => (string) Setting::read('shop.address'),
                'phone' => (string) Setting::read('shop.phone'),
            ],
        ]);
    }

    private function date(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
    }
}
