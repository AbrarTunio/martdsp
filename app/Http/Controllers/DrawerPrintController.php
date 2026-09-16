<?php

namespace App\Http\Controllers;

use App\Models\DrawerSession;
use App\Services\PrintService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

/**
 * Send the shift report to the counter printer.
 *
 * What the slip shows follows the same rule as the screen: a cashier counting
 * blind is not handed a paper copy of the figure they are meant to be
 * counting towards.
 */
class DrawerPrintController extends Controller
{
    public function __construct(private readonly PrintService $printing) {}

    public function __invoke(DrawerSession $drawer): RedirectResponse
    {
        Gate::authorize('view-drawer', $drawer);

        try {
            $printer = $this->printing->shiftReport($drawer, Gate::allows('see-expected-cash'));
        } catch (RuntimeException $failure) {
            return back()->withErrors(['printer' => $failure->getMessage()]);
        }

        return back()->with('status', __('The :which was sent to :printer.', [
            'which' => $drawer->isOpen() ? __('drawer summary') : __('end of sale report'),
            'printer' => $printer->name,
        ]));
    }
}
