<?php

namespace App\Http\Controllers;

use App\Http\Requests\CloseDrawerRequest;
use App\Models\DrawerSession;
use App\Models\Setting;
use App\Services\DrawerService;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

/**
 * The end of a shift: count the drawer note by note and close it.
 */
class DrawerCloseController extends Controller
{
    public function __construct(private readonly DrawerService $drawers) {}

    public function create(DrawerSession $drawer): View|RedirectResponse
    {
        Gate::authorize('view-drawer', $drawer);

        if (! $drawer->isOpen()) {
            return redirect()->route('drawer.show', $drawer);
        }

        $drawer->loadMissing(['register', 'opener']);
        $seesExpected = Gate::allows('see-expected-cash');

        return view('drawer.close', [
            'drawer' => $drawer,
            'seesExpected' => $seesExpected,
            'config' => [
                'denominations' => array_map('intval', config('supermart.cash.denominations')),
                'expectedPaisa' => $seesExpected ? $drawer->expectedCashPaisa() : null,
                'tolerancePaisa' => max(0, (int) Setting::read('drawer.variance_tolerance')) * 100,
                'floatPaisa' => $drawer->opening_float_paisa,
                'old' => [
                    'counts' => (array) old('counts', []),
                    'left' => old('left_in_drawer'),
                    'reason' => old('reason'),
                ],
            ],
        ]);
    }

    public function store(CloseDrawerRequest $request, DrawerSession $drawer): RedirectResponse
    {
        Gate::authorize('view-drawer', $drawer);

        try {
            $closed = $this->drawers->close(
                session: $drawer,
                user: $request->user(),
                counts: $request->counts(),
                leftInDrawerPaisa: $request->leftInDrawerPaisa(),
                reason: $request->validated('reason'),
            );
        } catch (RuntimeException $exception) {
            return back()->withErrors(['reason' => $exception->getMessage()])->withInput();
        }

        $message = $closed->isAwaitingApproval()
            ? __('Drawer closed. The count was out, so a manager needs to sign it off.')
            : __('Drawer closed with :amount counted.', ['amount' => Money::withSymbol((int) $closed->counted_cash_paisa)]);

        return redirect()->route('drawer.show', $closed)->with('status', $message);
    }
}
