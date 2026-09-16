<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Company names. Useful for filtering the product list and for reports that
 * ask which supplier's lines actually sell; nothing depends on them.
 */
class BrandController extends Controller
{
    public function index(): View
    {
        Gate::authorize('supervise');

        return view('products.brands.index', [
            'brands' => Brand::withCount('products')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('supervise');

        $brand = Brand::create($this->validated($request));

        return back()->with('status', __(':name was added.', ['name' => $brand->name]));
    }

    public function update(Request $request, Brand $brand): RedirectResponse
    {
        Gate::authorize('supervise');

        $brand->update($this->validated($request, $brand));

        return back()->with('status', __(':name was saved.', ['name' => $brand->name]));
    }

    public function destroy(Brand $brand): RedirectResponse
    {
        Gate::authorize('supervise');

        if ($brand->products()->exists()) {
            return back()->withErrors([
                'brand' => __('":name" is on :count product(s). Change those first, or just untick it to hide it.', [
                    'name' => $brand->name,
                    'count' => $brand->products()->count(),
                ]),
            ]);
        }

        $brand->delete();

        return back()->with('status', __('":name" was removed.', ['name' => $brand->name]));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Brand $brand = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('brands')->ignore($brand?->getKey())],
            'is_active' => ['boolean'],
        ]);

        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }
}
