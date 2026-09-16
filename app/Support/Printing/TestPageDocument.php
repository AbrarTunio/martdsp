<?php

namespace App\Support\Printing;

use App\Models\Printer;
use App\Models\Setting;
use App\Support\EscPos;
use App\Support\Money;

/**
 * The slip the Test Print button produces.
 *
 * It is built to fail visibly. The ruler line shows at a glance whether the
 * roll width in the settings matches the roll in the machine — if the bar
 * wraps onto a second line, the printer is narrower than it has been told.
 * The rest exercises everything a receipt relies on, so a printer that mangles
 * bold or ignores the cutter is found here rather than in front of a customer.
 */
final class TestPageDocument
{
    public static function render(Printer $printer): string
    {
        $escpos = new EscPos($printer->columns());
        $width = $escpos->width();

        $escpos->init()
            ->align('center')
            ->bold()
            ->size(1, 2)
            ->line(__('TEST PRINT'))
            ->size()
            ->bold(false)
            ->wrapped((string) Setting::read('shop.name') ?: (string) config('app.name'))
            ->line(now()->format('d/m/Y g:i A'))
            ->align('left')
            ->rule();

        $escpos->row(__('Printer'), $printer->name)
            ->row(__('Paper'), __(':mm mm', ['mm' => $printer->paper]))
            ->row(__('Characters per line'), (string) $width)
            ->row(__('Connection'), __($printer->channel->label()))
            ->rule();

        /* If this bar wraps, the paper width here is wrong. */
        $escpos->line(__('This bar must fit on one line:'))
            ->line(self::ruler($width))
            ->line();

        $escpos->bold()->line(__('Bold text looks like this'))->bold(false)
            ->line(__('Normal text looks like this'))
            ->size(1, 2)->line(__('Tall text'))->size()
            ->line();

        $escpos->align('center')->line(__('centred'))
            ->align('right')->line(__('right'))
            ->align('left')->line(__('left'))
            ->rule();

        $escpos->line(__('Money lines up on the right:'))
            ->row(__('Two items'), Money::format(125_000))
            ->row(__('GST included'), Money::format(19_068))
            ->bold()->row(__('TOTAL'), Money::withSymbol(125_000))->bold(false)
            ->rule();

        $escpos->align('center')
            ->wrapped($printer->has_drawer
                ? __('If the drawer did not open, try the other drawer pin in the printer settings.')
                : __('The paper should now be cut. If it is not, switch the cutter off in the printer settings.'))
            ->align('left');

        return $escpos->reset()
            ->cut($printer->cuts ? $printer->feed_lines : 0)
            ->bytes();
    }

    /**
     * A ruler marked every tenth column, ending exactly at the last one.
     */
    private static function ruler(int $width): string
    {
        $ruler = '';

        for ($column = 1; $column <= $width; $column++) {
            $ruler .= $column % 10 === 0 ? (string) (intdiv($column, 10) % 10) : '.';
        }

        return $ruler;
    }
}
