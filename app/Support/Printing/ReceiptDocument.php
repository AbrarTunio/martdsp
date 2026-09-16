<?php

namespace App\Support\Printing;

use App\Models\Printer;
use App\Models\Sale;
use App\Models\Setting;
use App\Support\EscPos;
use App\Support\Money;

/**
 * A sale, laid out for a thermal roll.
 *
 * This is the same receipt as the on-screen one, rebuilt against a column
 * count instead of a stylesheet. The order of the lines is deliberate and
 * matches what customers in Pakistan are used to reading: shop, bill number
 * and time, the items, what it came to, what was handed over, and what went
 * on the khata.
 */
final class ReceiptDocument
{
    /**
     * @return string the ESC/POS bytes for one receipt
     */
    public static function render(Sale $sale, Printer $printer): string
    {
        $sale->loadMissing(['items', 'payments', 'customer', 'register', 'user']);

        $escpos = new EscPos($printer->columns());

        $escpos->init();

        self::header($escpos);
        self::bill($escpos, $sale);
        self::items($escpos, $sale);
        self::totals($escpos, $sale);
        self::tenders($escpos, $sale);
        self::footer($escpos, $sale);

        return $escpos->reset()
            ->cut($printer->cuts ? $printer->feed_lines : 0)
            ->bytes();
    }

    private static function header(EscPos $escpos): void
    {
        $escpos->align('center')
            ->bold()
            ->size(1, 2)
            /* Long shop names are common, and the roll will not stretch. */
            ->wrapped((string) Setting::read('shop.name') ?: (string) config('app.name'))
            ->size()
            ->bold(false);

        foreach (['shop.address' => '', 'shop.phone' => 'Ph ', 'shop.ntn' => 'NTN ', 'shop.strn' => 'STRN '] as $key => $prefix) {
            $value = trim((string) Setting::read($key));

            if ($value !== '') {
                $escpos->wrapped($prefix.$value);
            }
        }

        $escpos->align('left')->rule();
    }

    private static function bill(EscPos $escpos, Sale $sale): void
    {
        $escpos->bold()
            ->row($sale->invoiceNumber(), (string) $sale->sold_at?->format('d/m/y g:i A'))
            ->bold(false)
            ->row((string) $sale->user?->name, (string) $sale->register?->name);

        if ($sale->customer) {
            $escpos->wrapped(__('Customer: :name', ['name' => $sale->customerName()]));
        }

        if ($sale->isVoid()) {
            $escpos->align('center')->bold()->size(2, 2)->line(__('CANCELLED'))->size()->bold(false)->align('left');
        }

        $escpos->rule();
    }

    /**
     * Each item over two lines: the name on its own so a long one is readable,
     * then how many at what price against the line total.
     */
    private static function items(EscPos $escpos, Sale $sale): void
    {
        foreach ($sale->items as $item) {
            $escpos->wrapped($item->name);

            $escpos->row(
                sprintf('  %s %s x %s', $item->qtyForInput(), $item->unit_name, Money::format($item->unit_price_paisa)),
                Money::format($item->line_total_paisa),
            );

            if ($item->discount_paisa > 0) {
                $escpos->row('  '.__('discount'), '-'.Money::format($item->discount_paisa));
            }
        }

        $escpos->rule();
    }

    private static function totals(EscPos $escpos, Sale $sale): void
    {
        $escpos->row(
            __('Items (:count)', ['count' => $sale->items->count()]),
            Money::format($sale->subtotal_paisa),
        );

        if ($sale->discount_paisa > 0) {
            $escpos->row(__('Discount'), '-'.Money::format($sale->discount_paisa));
        }

        if ($sale->tax_paisa > 0) {
            $escpos->row(
                $sale->prices_include_tax ? __('GST included') : __('GST'),
                ($sale->prices_include_tax ? '' : '+').Money::format($sale->tax_paisa),
            );
        }

        if ($sale->round_off_paisa !== 0) {
            $escpos->row(
                __('Rounded'),
                ($sale->round_off_paisa > 0 ? '+' : '-').Money::format(abs($sale->round_off_paisa)),
            );
        }

        $escpos->bold()
            ->size(1, 2)
            ->row(__('TOTAL'), Money::withSymbol($sale->total_paisa))
            ->size()
            ->bold(false)
            ->rule();
    }

    private static function tenders(EscPos $escpos, Sale $sale): void
    {
        foreach ($sale->payments as $payment) {
            $escpos->row(
                __($payment->method->label()).($payment->reference ? ' ('.$payment->reference.')' : ''),
                Money::format($payment->method->isCash() ? $payment->tendered_paisa : $payment->amount_paisa),
            );
        }

        if ($sale->change_given_paisa > 0) {
            $escpos->bold()->row(__('Change'), Money::format($sale->change_given_paisa))->bold(false);
        }

        if ($sale->due_paisa > 0) {
            $escpos->bold()->row(__('On khata'), Money::format($sale->due_paisa))->bold(false);

            if ($sale->customer) {
                $escpos->row(__('They now owe'), Money::format(max(0, (int) $sale->customer->balance_paisa)));
            }
        }
    }

    private static function footer(EscPos $escpos, Sale $sale): void
    {
        $escpos->rule()->align('center');

        $note = trim((string) Setting::read('receipt.footer_note'));

        if ($note !== '') {
            $escpos->wrapped($note);
        }

        if ($sale->due_paisa > 0) {
            $escpos->line(__('Khata bill - please keep this slip'));
        }

        $escpos->align('left');
    }
}
