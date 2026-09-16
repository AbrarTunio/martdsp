<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Http\Requests\StoreStaffRequest;
use App\Http\Requests\UpdateStaffRequest;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Staff accounts.
 *
 * There is no public registration (see routes/auth.php), so this is the only
 * way an account comes into existence. Accounts are deactivated rather than
 * deleted, because a departed cashier's sales and drawer sessions must stay
 * attributable to a real person.
 */
class StaffController extends Controller
{
    public function index(): View
    {
        Gate::authorize('manage-users');

        return view('settings.staff.index', [
            'staff' => User::query()
                ->orderBy('is_active', 'desc')
                ->orderBy('name')
                ->orderBy('id')
                ->get(),
        ]);
    }

    public function create(): View
    {
        Gate::authorize('manage-users');

        return view('settings.staff.create', [
            'roles' => Role::options(),
        ]);
    }

    public function store(StoreStaffRequest $request): RedirectResponse
    {
        $user = User::create($request->validated());

        ActivityLog::record('staff.created', $user, after: $user->only('name', 'email', 'role'));

        return redirect()
            ->route('settings.staff.index')
            ->with('status', __(':name can now log in.', ['name' => $user->name]));
    }

    public function edit(User $staff): View
    {
        Gate::authorize('manage-users');

        return view('settings.staff.edit', [
            'staff' => $staff,
            'roles' => Role::options(),
        ]);
    }

    public function update(UpdateStaffRequest $request, User $staff): RedirectResponse
    {
        $before = $staff->only('name', 'email', 'role', 'is_active');

        $attributes = $request->validated();

        /** A blank password field means the user keeps their current one. */
        if (blank($attributes['password'] ?? null)) {
            unset($attributes['password']);
        }

        if (blank($attributes['pin_code'] ?? null)) {
            unset($attributes['pin_code']);
        }

        $staff->update($attributes);

        ActivityLog::record('staff.updated', $staff, $before, $staff->only('name', 'email', 'role', 'is_active'));

        return redirect()
            ->route('settings.staff.index')
            ->with('status', __(':name was updated.', ['name' => $staff->name]));
    }

    /**
     * Deactivate, never delete. The route is DELETE because that is what the
     * resource verb is, but the effect is reversible.
     */
    public function destroy(User $staff): RedirectResponse
    {
        Gate::authorize('manage-users');

        if ($staff->is($this->currentUser())) {
            return back()->withErrors([
                'staff' => __('You cannot deactivate your own account.'),
            ]);
        }

        if ($staff->isOwner() && User::active()->where('role', Role::Owner)->count() <= 1) {
            return back()->withErrors([
                'staff' => __('The shop must always have at least one active owner.'),
            ]);
        }

        $staff->update(['is_active' => false]);

        ActivityLog::record('staff.deactivated', $staff);

        return redirect()
            ->route('settings.staff.index')
            ->with('status', __(':name can no longer log in.', ['name' => $staff->name]));
    }

    private function currentUser(): User
    {
        return auth()->user();
    }
}
