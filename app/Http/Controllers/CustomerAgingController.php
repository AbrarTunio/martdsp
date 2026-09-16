<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Setting;
use App\Support\KhataAging;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * How old the khata money is.
 *
 * A shop can carry a large khata comfortably as long as it keeps turning over.
 * What hurts is the money that stopped moving — so every customer is sorted by
 * how long their oldest unpaid bill has been sitting, not by how much they
 * owe.
 */
class CustomerAgingController extends Controller
{
    public function __invoke(Request $request): View
    {
        $customers = Customer::query()->owing()->orderBy('name')->get();

        $agings = KhataAging::forMany($customers);

        $bucket = (string) $request->query('bucket', 'all');

        if (in_array($bucket, KhataAging::BUCKETS, true)) {
            $customers = $customers->filter(
                fn (Customer $customer): bool => $agings[$customer->id]->paisa($bucket) > 0
            )->values();
        }

        $customers = $customers->sortByDesc(
            fn (Customer $customer): int => $agings[$customer->id]->daysLate()
        )->values();

        return view('customers.aging', [
            'customers' => $customers,
            'agings' => $agings,
            'totals' => KhataAging::totals($agings),
            'labels' => KhataAging::labels(),
            'tones' => KhataAging::tones(),
            'bucket' => $bucket,
            'creditDays' => (int) Setting::read('khata.credit_days'),
            'printedAt' => now(),
            'shop' => [
                'name' => (string) Setting::read('shop.name'),
                'phone' => (string) Setting::read('shop.phone'),
            ],
        ]);
    }
}
