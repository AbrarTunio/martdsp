<?php

namespace App\Http\Controllers;

use App\Enums\UnitType;
use App\Models\Unit;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * The words for sizes: sachet, box, carton, kilogram.
 *
 * A unit name carries no size of its own. "Box" means 24 sachets only for the
 * product that says so, which is why this list stays short and shared.
 */
class UnitController extends Controller
{
    public function index(): View
    {
        Gate::authorize('supervise');

        return view('products.units.index', [
            'units' => Unit::withCount('productUnits')->orderBy('name')->get(),
            'types' => UnitType::options(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('supervise');

        $unit = Unit::create($this->validated($request));

        return back()->with('status', __(':name was added.', ['name' => $unit->name]));
    }

    public function update(Request $request, Unit $unit): RedirectResponse
    {
        Gate::authorize('supervise');

        $unit->update($this->validated($request, $unit));

        return back()->with('status', __(':name was saved.', ['name' => $unit->name]));
    }

    public function destroy(Unit $unit): RedirectResponse
    {
        Gate::authorize('supervise');

        if ($unit->productUnits()->exists()) {
            return back()->withErrors([
                'unit' => __('":name" is used by :count packaging line(s). Remove it from those products first.', [
                    'name' => $unit->name,
                    'count' => $unit->productUnits()->count(),
                ]),
            ]);
        }

        $unit->delete();

        return back()->with('status', __('":name" was removed.', ['name' => $unit->name]));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Unit $unit = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:40', Rule::unique('units')->ignore($unit?->getKey())],
            'short_name' => ['required', 'string', 'max:12'],
            'type' => ['required', Rule::enum(UnitType::class)],
        ], [
            'short_name.required' => __('Give it a short form, like "pc" or "kg". It is what fits on a receipt line.'),
        ]);
    }
}
