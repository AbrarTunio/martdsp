<?php

namespace App\Http\Controllers;

use App\Models\Category;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Aisles, effectively. Two levels deep is plenty — "Snacks / Biscuits" is
 * findable; a third level is just another place to lose a product.
 */
class CategoryController extends Controller
{
    public function index(): View
    {
        Gate::authorize('supervise');

        return view('products.categories.index', [
            'categories' => Category::with(['children' => fn ($query) => $query->withCount('products')->orderBy('name')])
                ->withCount('products')
                ->topLevel()
                ->orderBy('name')
                ->get(),
            'parents' => Category::topLevel()->orderBy('name')->pluck('name', 'id'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('supervise');

        $category = Category::create($this->validated($request));

        return back()->with('status', __(':name was added.', ['name' => $category->fullName()]));
    }

    public function update(Request $request, Category $category): RedirectResponse
    {
        Gate::authorize('supervise');

        $category->update($this->validated($request, $category));

        return back()->with('status', __(':name was saved.', ['name' => $category->fullName()]));
    }

    public function destroy(Category $category): RedirectResponse
    {
        Gate::authorize('supervise');

        if ($category->products()->exists() || $category->children()->exists()) {
            return back()->withErrors([
                'category' => __('":name" is still in use. Move its products or sub-categories first.', [
                    'name' => $category->name,
                ]),
            ]);
        }

        $category->delete();

        return back()->with('status', __('":name" was removed.', ['name' => $category->name]));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Category $category = null): array
    {
        /* An empty select posts "", which would make the uniqueness check
           look for a parent with that id instead of for a top-level name. */
        $request->merge(['parent_id' => $request->filled('parent_id') ? $request->input('parent_id') : null]);

        $data = $request->validate([
            'name' => [
                'required', 'string', 'max:80',
                Rule::unique('categories')
                    ->where('parent_id', $request->input('parent_id'))
                    ->ignore($category?->getKey()),
            ],
            'name_ur' => ['nullable', 'string', 'max:80'],
            'parent_id' => [
                'nullable', 'integer',
                Rule::exists('categories', 'id')->whereNull('parent_id'),
                Rule::notIn(array_filter([$category?->getKey()])),
            ],
            'is_active' => ['boolean'],
        ], [
            'name.unique' => __('A category with this name is already here.'),
            'parent_id.not_in' => __('A category cannot sit inside itself.'),
        ]);

        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }
}
