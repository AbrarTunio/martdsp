<?php

namespace App\Http\Controllers;

use App\Models\StockBatch;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * What is about to go off.
 *
 * This is the one screen in the shop that earns money by being looked at in
 * the morning rather than at the end of the month. Stock nearing its date is
 * still worth its full price today, half of it next week and nothing at all
 * after that, so the list is ordered by how little time is left rather than by
 * how much money is involved.
 *
 * It is open to everyone on the till — moving short-dated stock to the front
 * is the cashier's job as much as the owner's — but what it is worth is held
 * back behind `see-financials`, the same way the stock list holds cost back.
 */
class StockExpiryController extends Controller
{
    /**
     * How far ahead the screen can look, in days. "Already gone" is its own
     * view, because binning is a different job from pushing stock.
     */
    private const WINDOWS = [7, 30, 90];

    public function __invoke(Request $request): View
    {
        $view = $this->chosenView($request);

        return view('stock.expiry', [
            'batches' => $this->listed($request, $view),
            'view' => $view,
            'views' => $this->viewOptions(),
            'filters' => $request->only('q'),
            'summary' => $this->summary(),
        ]);
    }

    /**
     * The layers worth showing, soonest first. Sold-out layers are left out
     * entirely: a batch with nothing on the shelf cannot go off.
     *
     * @return LengthAwarePaginator<int, StockBatch>
     */
    private function listed(Request $request, string $view): LengthAwarePaginator
    {
        $search = trim((string) $request->query('q', ''));

        return StockBatch::query()
            ->with(['product.baseUnit', 'product.productUnits.unit'])
            ->open()
            ->when($view === 'expired',
                fn (Builder $query) => $query->expired(),
                /* The two views partition the shelf between them: what is
                   already gone is binning work, what is still in date is
                   selling work, and nothing appears on both lists. */
                fn (Builder $query) => $query->stillSellable()->expiringWithin((int) $view),
            )
            ->when($search !== '', fn (Builder $query) => $query->whereHas(
                'product',
                fn (Builder $product) => $product->search($search),
            ))
            ->soonestFirst()
            ->paginate(30)
            ->withQueryString();
    }

    /**
     * The numbers across the top. Counts are of layers, not products, because
     * two dates on the same item are two separate jobs.
     *
     * @return array<string, int|null>
     */
    private function summary(): array
    {
        return [
            'expired' => StockBatch::query()->open()->expired()->count(),
            'week' => StockBatch::query()->open()->stillSellable()->expiringWithin(7)->count(),
            'month' => StockBatch::query()->open()->stillSellable()->expiringWithin(30)->count(),
            'at_risk_paisa' => Gate::allows('see-financials')
                ? (int) StockBatch::query()->open()->stillSellable()->expiringWithin(30)->sum(DB::raw('qty_base * cost_base_paisa'))
                : null,
        ];
    }

    private function chosenView(Request $request): string
    {
        $view = (string) $request->query('view', '30');

        return in_array($view, ['expired', '7', '30', '90'], true) ? $view : '30';
    }

    /**
     * @return array<string, string>
     */
    private function viewOptions(): array
    {
        $options = ['expired' => __('Already gone')];

        foreach (self::WINDOWS as $days) {
            $options[(string) $days] = trans_choice(
                'Going off within :count day|Going off within :count days',
                $days,
                ['count' => $days],
            );
        }

        return $options;
    }
}
