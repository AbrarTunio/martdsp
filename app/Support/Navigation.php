<?php

namespace App\Support;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

/**
 * The single definition of the application's navigation.
 *
 * Both the mobile bottom tab bar and the desktop sidebar read from here, so a
 * section can never appear in one and be forgotten in the other.
 *
 * @phpstan-type NavItem array{
 *     route: string,
 *     label: string,
 *     icon: string,
 *     ability: string|null,
 *     tab: bool,
 *     section: string
 * }
 */
class Navigation
{
    /**
     * Every navigable section, in sidebar order.
     *
     * `tab` marks the five items that earn a place in the phone bottom bar;
     * everything else is reachable from the "More" sheet.
     *
     * @return list<NavItem>
     */
    public static function all(): array
    {
        return [
            self::item('dashboard', 'Dashboard', 'home', section: 'main'),
            self::item('pos.index', 'Sell', 'cart', tab: true, section: 'main'),
            self::item('sales.index', 'Sales', 'receipt', section: 'main'),

            self::item('products.index', 'Products', 'box', section: 'inventory'),
            self::item('stock.index', 'Stock', 'layers', tab: true, section: 'inventory'),
            self::item('purchases.index', 'Purchases', 'truck', ability: 'supervise', section: 'inventory'),
            self::item('suppliers.index', 'Suppliers', 'factory', ability: 'supervise', section: 'inventory'),

            self::item('customers.index', 'Khata', 'book', tab: true, section: 'money'),
            self::item('drawer.index', 'Drawer', 'wallet', tab: true, section: 'money'),
            self::item('reports.index', 'Reports', 'chart', ability: 'see-financials', section: 'money'),

            self::item('activity.index', 'Activity', 'clock', ability: 'supervise', section: 'system'),
            self::item('settings.index', 'Settings', 'cog', ability: 'manage-settings', section: 'system'),
        ];
    }

    /**
     * The items the current user may actually reach. An item whose route does
     * not exist yet is dropped rather than throwing, so the shell stays usable
     * while later phases are still being built.
     *
     * @return list<NavItem>
     */
    public static function visible(): array
    {
        return array_values(array_filter(
            self::all(),
            fn (array $item): bool => Route::has($item['route'])
                && ($item['ability'] === null || Gate::allows($item['ability'])),
        ));
    }

    /**
     * The four-or-five primary items for the phone bottom tab bar.
     *
     * @return list<NavItem>
     */
    public static function tabs(): array
    {
        return array_values(array_filter(
            self::visible(),
            fn (array $item): bool => $item['tab'],
        ));
    }

    /**
     * Visible items grouped by section, for the desktop sidebar.
     *
     * @return array<string, list<NavItem>>
     */
    public static function grouped(): array
    {
        $grouped = [];

        foreach (self::visible() as $item) {
            $grouped[$item['section']][] = $item;
        }

        return $grouped;
    }

    /**
     * Whether the given nav route is the one currently being viewed. Matches
     * child routes too, so `products.edit` still lights up "Products".
     */
    public static function isActive(string $route): bool
    {
        $prefix = str_contains($route, '.')
            ? substr($route, 0, strrpos($route, '.') + 1)
            : $route;

        return request()->routeIs($route) || request()->routeIs($prefix.'*');
    }

    /**
     * @return NavItem
     */
    private static function item(
        string $route,
        string $label,
        string $icon,
        ?string $ability = null,
        bool $tab = false,
        string $section = 'main',
    ): array {
        return [
            'route' => $route,
            'label' => $label,
            'icon' => $icon,
            'ability' => $ability,
            'tab' => $tab,
            'section' => $section,
        ];
    }
}
