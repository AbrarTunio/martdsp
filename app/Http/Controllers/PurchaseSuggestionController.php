<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Supplier;
use App\Services\PurchaseSuggestionService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

/**
 * The reorder list: what is running low, how much to order, and from whom.
 */
class PurchaseSuggestionController extends Controller
{
    public function __construct(private readonly PurchaseSuggestionService $suggestions) {}

    public function index(): View
    {
        Gate::authorize('supervise');

        $groups = $this->suggestions->grouped();

        return view('purchases.suggestions', [
            'groups' => $groups,
            'suppliers' => Supplier::active()->orderBy('name')->get(),
            'lineCount' => $groups->sum(fn (array $group): int => $group['lines']->count()),
            'totalPaisa' => (int) $groups->sum('total_paisa'),
        ]);
    }

    /**
     * Turn the ticked lines into a draft purchase for one supplier. The
     * quantities are worked out again here rather than trusted from the page,
     * in case something sold while the list was open.
     */
    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('supervise');

        $validated = $request->validate([
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'product_ids' => ['required', 'array', 'min:1', 'max:300'],
            'product_ids.*' => ['integer', 'exists:products,id'],
        ], [
            'product_ids.required' => __('Tick at least one item to order.'),
        ]);

        $supplier = isset($validated['supplier_id']) ? Supplier::find($validated['supplier_id']) : null;

        try {
            $purchase = $this->suggestions->startDraft(
                $supplier,
                array_map('intval', $validated['product_ids']),
                $request->user(),
            );
        } catch (RuntimeException $exception) {
            return back()->withErrors(['product_ids' => $exception->getMessage()]);
        }

        ActivityLog::record('purchase.drafted_from_reorder_list', $purchase, after: [
            'reference' => $purchase->reference,
            'lines' => $purchase->items()->count(),
        ]);

        return redirect()
            ->route('purchases.edit', $purchase)
            ->with('status', __(':reference is started. Check the quantities and prices against the delivery when it arrives.', [
                'reference' => $purchase->reference,
            ]));
    }
}
