<?php

namespace App\Http\Controllers;

use App\Models\Printer;
use App\Services\PrintService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

/**
 * The two buttons that prove a printer is really wired up.
 */
class PrinterTestController extends Controller
{
    public function __construct(private readonly PrintService $printing) {}

    public function print(Printer $printer): RedirectResponse
    {
        Gate::authorize('manage-settings');

        try {
            $this->printing->testPage($printer);
        } catch (RuntimeException $failure) {
            return back()->withErrors(['printer' => $failure->getMessage()]);
        }

        return back()->with('status', __('A test slip was sent to :name. Check the paper width against the ruler line on it.', [
            'name' => $printer->name,
        ]));
    }

    /**
     * Popping the drawer without a sale is something a manager signs off on,
     * because it is also how cash walks out of a shop.
     */
    public function pulse(Printer $printer): RedirectResponse
    {
        Gate::authorize('supervise');

        try {
            $this->printing->openDrawer($printer);
        } catch (RuntimeException $failure) {
            return back()->withErrors(['printer' => $failure->getMessage()]);
        }

        return back()->with('status', __('The drawer on :name was told to open.', ['name' => $printer->name]));
    }
}
