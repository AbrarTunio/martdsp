<?php

namespace App\Support\Insights;

use App\Support\Insights\Packs\BusinessPack;
use App\Support\Insights\Packs\BuyingPack;
use App\Support\Insights\Packs\DrawerPack;
use App\Support\Insights\Packs\KhataPack;
use App\Support\Insights\Packs\Pack;
use App\Support\Insights\Packs\SalesPack;
use App\Support\Insights\Packs\StockPack;

/**
 * Which set of figures belongs to the page the person is looking at.
 *
 * The button sends the route it sits on; this turns that into one of the
 * six areas the shop is explained in.
 */
final class InsightRegistry
{
    /**
     * @var array<string, class-string<Pack>>
     */
    private const PACKS = [
        'business' => BusinessPack::class,
        'sales' => SalesPack::class,
        'stock' => StockPack::class,
        'khata' => KhataPack::class,
        'drawer' => DrawerPack::class,
        'buying' => BuyingPack::class,
    ];

    /**
     * The report pages, which all share one route name.
     *
     * @var array<string, string>
     */
    private const REPORTS = [
        'daily-sales' => 'sales',
        'profit' => 'sales',
        'stock-value' => 'stock',
        'dead-stock' => 'stock',
        'cashiers' => 'drawer',
        'suppliers' => 'buying',
    ];

    /**
     * Everything before the first dot in a route name, and what it explains.
     *
     * @var array<string, string>
     */
    private const AREAS = [
        'dashboard' => 'business',
        'pos' => 'sales',
        'sales' => 'sales',
        'products' => 'stock',
        'stock' => 'stock',
        'customers' => 'khata',
        'drawer' => 'drawer',
        'purchases' => 'buying',
        'suppliers' => 'buying',
    ];

    /**
     * @return list<string>
     */
    public static function pages(): array
    {
        return array_keys(self::PACKS);
    }

    public static function has(string $page): bool
    {
        return array_key_exists($page, self::PACKS);
    }

    /**
     * The area a route belongs to, or null when the page has nothing to say.
     *
     * @param  string|null  $key  the report on the page, for the report routes
     */
    public static function forRoute(?string $route, ?string $key = null): ?string
    {
        if ($route === null) {
            return null;
        }

        if ($route === 'reports.index') {
            return 'business';
        }

        if ($route === 'reports.show') {
            return self::REPORTS[$key] ?? null;
        }

        return self::AREAS[explode('.', $route)[0]] ?? null;
    }

    public function build(string $page, InsightScope $scope): MetricPack
    {
        $pack = self::PACKS[$page] ?? null;

        abort_if($pack === null, 404);

        return app($pack)->build($scope);
    }
}
