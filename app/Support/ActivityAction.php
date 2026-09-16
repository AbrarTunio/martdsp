<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Plain words for the things the shop records.
 *
 * Every auditable action is stored as a short code such as `sale.voided`. This
 * turns that code into a line a shopkeeper can read, sorts it into an area of
 * the shop so the log can be filtered, and gives it a colour so a cancelled
 * bill stands out from an ordinary one.
 *
 * An action nobody has named here still shows up — the code is tidied into
 * words rather than hidden — so a new action added later can never go missing
 * from the log.
 */
class ActivityAction
{
    /**
     * The areas of the shop, and the action prefixes that belong to each.
     *
     * @var array<string, list<string>>
     */
    public const AREAS = [
        'sales' => ['sale.'],
        'stock' => ['product.', 'stock.'],
        'purchases' => ['purchase.', 'supplier.'],
        'khata' => ['customer.'],
        'drawer' => ['drawer.', 'open.drawer'],
        'printing' => ['printer.', 'print.'],
        'people' => ['staff.', 'register.'],
        'settings' => ['settings.'],
        'backups' => ['backup.'],
        'reports' => ['report.'],
    ];

    /**
     * What each action is called on the screen.
     *
     * @var array<string, string>
     */
    private const LABELS = [
        'sale.completed' => 'Bill rung up',
        'sale.voided' => 'Bill cancelled',
        'sale.returned' => 'Goods returned',

        'product.created' => 'Product added',
        'product.updated' => 'Product changed',
        'product.hidden' => 'Product hidden',
        'stock.adjustment.created' => 'Stock correction started',
        'stock.adjustment.updated' => 'Stock correction changed',
        'stock.adjustment.posted' => 'Stock correction applied',
        'stock.adjustment.cancelled' => 'Stock correction cancelled',
        'stock.take.created' => 'Stock count started',
        'stock.take.updated' => 'Stock count changed',
        'stock.take.posted' => 'Stock count applied',
        'stock.take.cancelled' => 'Stock count cancelled',

        'purchase.created' => 'Purchase entered',
        'purchase.updated' => 'Purchase changed',
        'purchase.received' => 'Goods received',
        'purchase.returned' => 'Goods sent back',
        'purchase.cancelled' => 'Purchase cancelled',
        'purchase.drafted_from_reorder_list' => 'Purchase drafted from the reorder list',
        'supplier.created' => 'Supplier added',
        'supplier.updated' => 'Supplier changed',
        'supplier.hidden' => 'Supplier hidden',
        'supplier.paid' => 'Supplier paid',
        'supplier.adjusted' => 'Supplier balance corrected',

        'customer.created' => 'Customer added',
        'customer.updated' => 'Customer changed',
        'customer.hidden' => 'Customer hidden',
        'customer.paid' => 'Khata payment taken',
        'customer.adjusted' => 'Khata balance corrected',
        'customer.written_off' => 'Khata written off',

        'drawer.opened' => 'Drawer opened for the shift',
        'drawer.closed' => 'Drawer counted and closed',
        'drawer.approved' => 'Drawer count signed off',
        'drawer.opening_float' => 'Opening money put in',
        'drawer.cash_sale' => 'Cash taken in',
        'drawer.sale_void' => 'Cash taken back out',
        'drawer.pay_in' => 'Money put in',
        'drawer.pay_out' => 'Money taken out',
        'drawer.safe_drop' => 'Money moved to the safe',
        'drawer.khata_payment' => 'Khata payment into the drawer',
        'drawer.refund' => 'Refund out of the drawer',
        'open.drawer' => 'Cash drawer popped open',

        'printer.created' => 'Printer added',
        'printer.updated' => 'Printer changed',
        'printer.deactivated' => 'Printer switched off',
        'printer.deleted' => 'Printer removed',
        'print.test' => 'Test print sent',

        'staff.created' => 'Staff member added',
        'staff.updated' => 'Staff member changed',
        'staff.deactivated' => 'Staff member switched off',
        'register.created' => 'Counter added',
        'register.updated' => 'Counter changed',
        'register.deactivated' => 'Counter switched off',
        'register.deleted' => 'Counter removed',

        'settings.ai_updated' => 'AI settings changed',
        'settings.ai_key_forgotten' => 'AI key removed',
        'settings.backup_updated' => 'Backup settings changed',

        'backup.created' => 'Backup made',
        'backup.failed' => 'Backup did not work',
        'backup.downloaded' => 'Backup downloaded',
        'backup.deleted' => 'Backup deleted',

        'report.exported' => 'Report exported',
    ];

    /**
     * Actions that undo, cancel or fail — the ones worth spotting from across
     * the room.
     *
     * @var list<string>
     */
    private const ALARMING = [
        'sale.voided',
        'sale.returned',
        'purchase.cancelled',
        'purchase.returned',
        'customer.written_off',
        'customer.adjusted',
        'supplier.adjusted',
        'stock.adjustment.posted',
        'stock.take.posted',
        'backup.failed',
        'staff.deactivated',
        'product.hidden',
        'customer.hidden',
        'supplier.hidden',
    ];

    public static function label(string $action): string
    {
        return __(self::LABELS[$action] ?? Str::ucfirst(str_replace(['.', '_'], ' ', $action)));
    }

    /**
     * Which part of the shop an action belongs to, for the filter.
     */
    public static function area(string $action): ?string
    {
        foreach (self::AREAS as $area => $prefixes) {
            foreach ($prefixes as $prefix) {
                if (str_starts_with($action, $prefix)) {
                    return $area;
                }
            }
        }

        return null;
    }

    /**
     * The areas, in the order the filter lists them.
     *
     * @return array<string, string>
     */
    public static function areas(): array
    {
        return [
            'sales' => __('Sales'),
            'stock' => __('Products and stock'),
            'purchases' => __('Purchases and suppliers'),
            'khata' => __('Khata'),
            'drawer' => __('Drawer'),
            'printing' => __('Printing'),
            'people' => __('Staff and counters'),
            'settings' => __('Settings'),
            'backups' => __('Backups'),
            'reports' => __('Reports'),
        ];
    }

    /**
     * The `LIKE` patterns that pull one area out of the log.
     *
     * @return list<string>
     */
    public static function patterns(string $area): array
    {
        return array_map(
            fn (string $prefix): string => $prefix.'%',
            self::AREAS[$area] ?? [],
        );
    }

    /**
     * The badge colour: red for anything that undoes or fails, grey otherwise.
     */
    public static function tone(string $action): string
    {
        return in_array($action, self::ALARMING, true) ? 'danger' : 'neutral';
    }

    /**
     * The icon that sits beside the line, taken from its area.
     */
    public static function icon(string $action): string
    {
        return match (self::area($action)) {
            'sales' => 'receipt',
            'stock' => 'box',
            'purchases' => 'truck',
            'khata' => 'book',
            'drawer' => 'wallet',
            'printing' => 'printer',
            'people' => 'users',
            'backups' => 'download',
            'reports' => 'chart',
            default => 'cog',
        };
    }
}
