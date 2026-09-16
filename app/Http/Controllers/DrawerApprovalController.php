<?php

namespace App\Http\Controllers;

use App\Http\Requests\ApproveDrawerRequest;
use App\Models\DrawerSession;
use App\Services\DrawerService;
use Illuminate\Http\RedirectResponse;
use RuntimeException;

/**
 * A manager signing off a drawer count that was out by more than the
 * tolerance.
 */
class DrawerApprovalController extends Controller
{
    public function __construct(private readonly DrawerService $drawers) {}

    public function __invoke(ApproveDrawerRequest $request, DrawerSession $drawer): RedirectResponse
    {
        try {
            $this->drawers->approve($drawer, $request->user(), $request->validated('note'));
        } catch (RuntimeException $exception) {
            return back()->withErrors(['note' => $exception->getMessage()]);
        }

        return redirect()->route('drawer.show', $drawer)->with('status', __('Count signed off.'));
    }
}
