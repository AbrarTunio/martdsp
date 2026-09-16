<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Services\StarterCatalogueService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * The "Seed Products" button: the everyday Pakistani grocery list, put in
 * once, so a new shop has something on its shelves list from the first day.
 */
class StarterCatalogueController extends Controller
{
    public function __invoke(StarterCatalogueService $catalogue): RedirectResponse
    {
        Gate::authorize('supervise');

        if ($catalogue->isInstalled()) {
            return redirect()
                ->route('products.index')
                ->with('status', __('The ready-made product list is already in. Nothing was changed.'));
        }

        $added = $catalogue->install();

        ActivityLog::record('catalogue.seeded', after: ['products_added' => $added]);

        return redirect()
            ->route('products.index')
            ->with('status', trans_choice(
                ':count product was added. Check the prices, then scan each barcode onto its product before you sell it.|:count products were added. Check the prices, then scan each barcode onto its product before you sell it.',
                $added,
                ['count' => $added],
            ));
    }
}
