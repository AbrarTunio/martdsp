<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * A staff member's own account details.
 *
 * There is deliberately no self-delete: a cashier's sales and drawer sessions
 * must stay attributable. Only an owner can deactivate an account, from
 * Settings -> Staff.
 */
class ProfileController extends Controller
{
    public function edit(Request $request): View
    {
        return view('profile.edit', [
            'user' => $request->user(),
        ]);
    }

    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->update($request->validated());

        return back()->with('status', __('Your details were saved.'));
    }
}
