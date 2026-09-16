<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreRegisterRequest;
use App\Http\Requests\UpdateRegisterRequest;
use App\Models\ActivityLog;
use App\Models\Printer;
use App\Models\Register;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * The counters in the shop, each with its own drawer and receipt printer.
 */
class RegisterController extends Controller
{
    public function index(): View
    {
        Gate::authorize('manage-settings');

        Register::ensureOne();

        return view('settings.registers.index', [
            'registers' => Register::query()->with('printer')->withCount('sales')->orderByDesc('is_active')->orderBy('name')->get(),
            'papers' => Register::paperOptions(),
            'printers' => Printer::query()->active()->get(),
        ]);
    }

    public function store(StoreRegisterRequest $request): RedirectResponse
    {
        $register = Register::query()->create($request->validated());

        ActivityLog::record('register.created', $register, after: $register->only('name', 'location', 'printer_id', 'printer_profile'));

        return back()->with('status', __(':name was added.', ['name' => $register->name]));
    }

    public function update(UpdateRegisterRequest $request, Register $register): RedirectResponse
    {
        if (! $request->validated('is_active') && $register->is_active && $this->isTheLastActive($register)) {
            return back()->withErrors(['register' => __('The shop needs at least one counter to sell from.')]);
        }

        $before = $register->only('name', 'location', 'printer_id', 'printer_profile', 'is_active');

        $register->update($request->validated());

        ActivityLog::record('register.updated', $register, $before, $register->only('name', 'location', 'printer_id', 'printer_profile', 'is_active'));

        return back()->with('status', __(':name was saved.', ['name' => $register->name]));
    }

    /**
     * A counter that has sold something keeps its history, so it is only
     * switched off. One that never sold anything is removed outright.
     */
    public function destroy(Register $register): RedirectResponse
    {
        Gate::authorize('manage-settings');

        if ($register->is_active && $this->isTheLastActive($register)) {
            return back()->withErrors(['register' => __('The shop needs at least one counter to sell from.')]);
        }

        if ($register->sales()->exists()) {
            $register->update(['is_active' => false]);

            ActivityLog::record('register.deactivated', $register);

            return back()->with('status', __(':name has sales on record, so it was switched off rather than removed.', ['name' => $register->name]));
        }

        ActivityLog::record('register.deleted', $register, $register->only('name'));

        $register->delete();

        return back()->with('status', __(':name was removed.', ['name' => $register->name]));
    }

    private function isTheLastActive(Register $register): bool
    {
        return Register::query()->where('is_active', true)->whereKeyNot($register->getKey())->doesntExist();
    }
}
