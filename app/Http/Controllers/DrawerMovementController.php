<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDrawerMovementRequest;
use App\Models\DrawerSession;
use App\Services\DrawerService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

/**
 * Cash added, paid out, or taken away to the safe while a shift runs.
 */
class DrawerMovementController extends Controller
{
    public function __construct(private readonly DrawerService $drawers) {}

    public function store(StoreDrawerMovementRequest $request, DrawerSession $drawer): RedirectResponse
    {
        Gate::authorize('view-drawer', $drawer);

        $type = $request->entryType();

        try {
            $this->drawers->move($drawer, $request->user(), $type, $request->amountPaisa(), $request->validated('note'));
        } catch (RuntimeException $exception) {
            return back()->withErrors(['amount' => $exception->getMessage()])->withInput();
        }

        return redirect()
            ->route('drawer.show', $drawer)
            ->with('status', __(':type: :amount recorded.', [
                'type' => __($type->label()),
                'amount' => Money::withSymbol($request->amountPaisa()),
            ]));
    }
}
