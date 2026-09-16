<?php

namespace App\Support\Reports;

/**
 * Every report in the app, grouped the way the reports screen shows them.
 *
 * The key is what appears in the address — /reports/daily-sales — so it is
 * never changed once a report exists: someone will have it bookmarked.
 */
final class ReportRegistry
{
    /**
     * @var array<string, array<string, class-string<Report>>>
     */
    private const SECTIONS = [
        'Sales' => [
            'daily-sales' => DailySalesReport::class,
            'profit' => ProfitReport::class,
        ],
        'Stock' => [
            'stock-value' => StockValuationReport::class,
            'dead-stock' => DeadStockReport::class,
        ],
        'People' => [
            'cashiers' => CashierReport::class,
            'suppliers' => SupplierReport::class,
        ],
    ];

    public static function find(string $key): ?Report
    {
        foreach (self::SECTIONS as $reports) {
            if (isset($reports[$key])) {
                return app($reports[$key]);
            }
        }

        return null;
    }

    /**
     * @return array<string, array<string, Report>>
     */
    public static function sections(): array
    {
        return collect(self::SECTIONS)
            ->mapWithKeys(fn (array $reports, string $section): array => [
                __($section) => collect($reports)->map(fn (string $class): Report => app($class))->all(),
            ])
            ->all();
    }
}
