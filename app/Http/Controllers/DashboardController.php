<?php

namespace App\Http\Controllers;

use App\Support\Reports\Dashboard;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class DashboardController extends Controller
{
    /**
     * The shop's home screen.
     *
     * Everyone sees their own day at the till, the drawers that are open and
     * anything on the shelves that needs attention. The owner and manager
     * also see the takings, the profit, and where the money is — a cashier
     * never sees profit, cost or the shop's totals.
     */
    public function __invoke(Request $request, Dashboard $dashboard): View
    {
        $user = $request->user();
        $financials = Gate::allows('see-financials');

        return view('dashboard', [
            'financials' => $financials,
            'seesExpected' => Gate::allows('see-expected-cash'),
            'counter' => $financials ? null : $dashboard->counter($user),
            'today' => $financials ? $dashboard->today() : null,
            'month' => $financials ? $dashboard->month() : null,
            'money' => $financials ? $dashboard->money() : null,
            'trend' => $financials ? $dashboard->trend() : null,
            'topProducts' => $financials ? $dashboard->topProducts() : null,
            'categoryMix' => $financials ? $dashboard->categoryMix() : null,
            'drawers' => $dashboard->openDrawers(),
            'warnings' => $dashboard->warnings($user),
        ]);
    }
}
