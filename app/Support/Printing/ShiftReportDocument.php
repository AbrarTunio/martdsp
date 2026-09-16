<?php

namespace App\Support\Printing;

use App\Models\DrawerSession;
use App\Models\Printer;
use App\Models\Setting;
use App\Support\DrawerSummary;
use App\Support\EscPos;
use App\Support\Money;

/**
 * A shift's drawer report, laid out for a thermal roll.
 *
 * An X-report is a peek while the drawer is still open; a Z-report is the
 * end of the sale, printed once the count is done. They are the same document
 * — the difference is only whether there is a count to compare against, which
 * is why one class prints both.
 *
 * The expected cash is left off when the reader is not allowed to see it, so
 * that a cashier printing their own X-report is not handed the answer they
 * are about to be asked to count to.
 */
final class ShiftReportDocument
{
    public static function render(DrawerSession $drawer, Printer $printer, bool $showsExpected = true): string
    {
        $drawer->loadMissing(['register', 'opener', 'closer']);

        $summary = DrawerSummary::for($drawer);
        $escpos = new EscPos($printer->columns());

        $escpos->init();

        self::header($escpos, $drawer);
        self::shift($escpos, $drawer);
        self::movements($escpos, $summary, $showsExpected);
        self::sales($escpos, $summary);
        self::count($escpos, $drawer, $summary, $showsExpected);
        self::signature($escpos, $drawer);

        return $escpos->reset()
            ->cut($printer->cuts ? $printer->feed_lines : 0)
            ->bytes();
    }

    private static function header(EscPos $escpos, DrawerSession $drawer): void
    {
        $escpos->align('center')
            ->bold()
            ->wrapped((string) Setting::read('shop.name') ?: (string) config('app.name'))
            ->size(1, 2)
            ->line($drawer->isOpen() ? __('X-REPORT') : __('Z-REPORT'))
            ->size()
            ->bold(false);

        $escpos->line($drawer->isOpen() ? __('Drawer still open') : __('End of sale'))
            ->align('left')
            ->rule();
    }

    private static function shift(EscPos $escpos, DrawerSession $drawer): void
    {
        $escpos->row(__('Counter'), (string) $drawer->register?->name)
            ->row(__('Opened by'), (string) $drawer->opener?->name)
            ->row(__('Opened'), (string) $drawer->opened_at?->format('d/m/y g:i A'));

        if ($drawer->closed_at) {
            $escpos->row(__('Closed by'), (string) $drawer->closer?->name)
                ->row(__('Closed'), $drawer->closed_at->format('d/m/y g:i A'));
        }

        $escpos->row(__('Printed'), now()->format('d/m/y g:i A'))->rule();
    }

    private static function movements(EscPos $escpos, DrawerSummary $summary, bool $showsExpected): void
    {
        $escpos->bold()->line(__('CASH IN THE DRAWER'))->bold(false);

        foreach ($summary->lines() as $line) {
            $label = __($line['type']->label());
            $escpos->row(
                $line['count'] > 1 ? $label.' ('.$line['count'].')' : $label,
                ($line['paisa'] < 0 ? '-' : '').Money::format(abs($line['paisa'])),
            );
        }

        if ($showsExpected) {
            $escpos->bold()->row(__('Should be in the drawer'), Money::withSymbol($summary->expectedPaisa))->bold(false);
        }

        $escpos->rule();
    }

    private static function sales(EscPos $escpos, DrawerSummary $summary): void
    {
        $escpos->bold()->line(__('SALES THIS SHIFT'))->bold(false);

        $escpos->row(__('Bills'), (string) $summary->billCount)
            ->row(__('Sold'), Money::format($summary->billTotalPaisa));

        if ($summary->discountPaisa > 0) {
            $escpos->row(__('Discount given'), Money::format($summary->discountPaisa));
        }

        if ($summary->taxPaisa > 0) {
            $escpos->row(__('GST'), Money::format($summary->taxPaisa));
        }

        if ($summary->voidedCount > 0) {
            $escpos->row(__('Cancelled bills'), (string) $summary->voidedCount);
        }

        if ($summary->tenderLines() !== []) {
            $escpos->line();

            foreach ($summary->tenderLines() as $line) {
                $escpos->row('  '.__($line['method']->label()), Money::format($line['paisa']));
            }
        }

        $escpos->rule();
    }

    private static function count(EscPos $escpos, DrawerSession $drawer, DrawerSummary $summary, bool $showsExpected): void
    {
        if ($drawer->isOpen() || $drawer->counted_cash_paisa === null) {
            return;
        }

        $escpos->bold()->line(__('THE COUNT'))->bold(false);

        $escpos->row(__('Counted'), Money::withSymbol((int) $drawer->counted_cash_paisa));

        if ($showsExpected) {
            $variance = (int) $drawer->variance_paisa;

            $escpos->row(__('Expected'), Money::format($summary->expectedPaisa))
                ->bold()
                ->row(
                    $variance === 0 ? __('Spot on') : ($variance > 0 ? __('Over by') : __('Short by')),
                    Money::withSymbol(abs($variance)),
                )
                ->bold(false);
        }

        if ($drawer->left_in_drawer_paisa !== null) {
            $escpos->row(__('Left for the next shift'), Money::format((int) $drawer->left_in_drawer_paisa))
                ->row(__('Taken away'), Money::format($summary->takenAwayPaisa()));
        }

        if ($drawer->variance_reason) {
            $escpos->line()->wrapped(__('Reason: :why', ['why' => $drawer->variance_reason]));
        }

        $escpos->rule();
    }

    private static function signature(EscPos $escpos, DrawerSession $drawer): void
    {
        if ($drawer->isOpen()) {
            $escpos->align('center')->wrapped(__('This is a peek, not the end of the sale.'))->align('left');

            return;
        }

        $escpos->feed(2)
            ->line(str_repeat('_', min(24, $escpos->width())))
            ->line(__('Cashier'))
            ->feed(2)
            ->line(str_repeat('_', min(24, $escpos->width())))
            ->line(__('Manager'));
    }
}
