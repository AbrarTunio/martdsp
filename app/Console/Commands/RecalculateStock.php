<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\StockService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * Rebuilds every cached stock balance from the movement ledger.
 *
 * The cached balance on `products` exists only so the shop's screens are
 * fast. This command is what keeps it honest: it replays the ledger and
 * reports anything that had drifted. `--check` answers the question without
 * touching a row, which is the form to reach for when something looks wrong
 * and nobody yet knows why.
 */
class RecalculateStock extends Command
{
    protected $signature = 'stock:recalculate
                            {--product= : Limit to one product, by id or item code}
                            {--check : Report drift without correcting anything}';

    protected $description = 'Rebuild cached stock balances and average cost from the movement ledger';

    public function handle(StockService $stock): int
    {
        $products = $this->products();

        if ($products->isEmpty()) {
            $this->components->warn('No products matched.');

            return self::FAILURE;
        }

        $checkOnly = (bool) $this->option('check');
        $drifted = [];

        $this->components->info(($checkOnly ? 'Checking ' : 'Rebuilding ').$products->count().' product(s) from the ledger.');

        $bar = $this->output->createProgressBar($products->count());
        $bar->start();

        foreach ($products as $product) {
            $result = $checkOnly ? $this->inspect($product) : $stock->rebuild($product);

            if ($result['drifted']) {
                $drifted[] = [
                    $product->sku,
                    $product->name,
                    $result['balance_before'],
                    $result['balance_after'],
                    $result['average_before'],
                    $result['average_after'],
                ];
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        return $this->report($drifted, $products->count(), $checkOnly);
    }

    /**
     * @return Collection<int, Product>
     */
    private function products()
    {
        $query = Product::query()->orderBy('id');

        if ($filter = $this->option('product')) {
            $query->where(fn ($query) => $query->where('id', $filter)->orWhere('sku', $filter));
        }

        return $query->get();
    }

    /**
     * The same replay as a rebuild, without writing anything back.
     *
     * @return array{balance_before: int, balance_after: int, average_before: int, average_after: int, drifted: bool}
     */
    private function inspect(Product $product): array
    {
        $balance = (int) $product->movements()->inLedgerOrder()->sum('qty_base');
        $average = (int) ($product->movements()->inLedgerOrder()->get()->last()?->avg_cost_after_paisa ?? 0);

        return [
            'balance_before' => (int) $product->stock_qty_base,
            'balance_after' => $balance,
            'average_before' => (int) $product->avg_cost_base_paisa,
            'average_after' => $average,
            'drifted' => (int) $product->stock_qty_base !== $balance
                || (int) $product->avg_cost_base_paisa !== $average,
        ];
    }

    /**
     * @param  array<int, array<int, mixed>>  $drifted
     */
    private function report(array $drifted, int $total, bool $checkOnly): int
    {
        if ($drifted === []) {
            $this->components->info("All {$total} product(s) agree with the ledger.");

            return self::SUCCESS;
        }

        $this->components->warn(count($drifted).' product(s) had drifted'.($checkOnly ? '' : ' and were corrected').':');

        $this->table(
            ['Item code', 'Name', 'Cached qty', 'Ledger qty', 'Cached cost', 'Ledger cost'],
            $drifted,
        );

        /* A non-zero exit so a scheduled --check run is noticed rather than
           scrolling past in a log nobody reads. */
        return $checkOnly ? self::FAILURE : self::SUCCESS;
    }
}
