<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\DrawerSession;
use App\Models\Printer;
use App\Models\Register;
use App\Models\Sale;
use App\Models\Setting;
use App\Support\EscPos;
use App\Support\Printing\PrinterDrivers;
use App\Support\Printing\ReceiptDocument;
use App\Support\Printing\ShiftReportDocument;
use App\Support\Printing\TestPageDocument;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Everything the shop sends to a counter printer.
 *
 * The documents know how to lay themselves out and the drivers know how to
 * reach the hardware; this is the piece that decides which printer a job
 * belongs to and carries the job over. Callers get one of two behaviours: the
 * loud ones throw, because the cashier pressed Print and deserves to be told
 * why nothing came out, and the quiet one swallows and logs, because a
 * printer that has run out of paper must never lose a sale.
 */
class PrintService
{
    public function __construct(private readonly PrinterDrivers $drivers) {}

    /**
     * The printer standing at a counter, or null when there is none wired up
     * and the slip has to go through the browser instead.
     */
    public function printerFor(?Register $register): ?Printer
    {
        return Printer::forRegister($register);
    }

    /**
     * Print a customer's slip.
     *
     * @throws RuntimeException when there is no printer, or it will not take the job
     */
    public function receipt(Sale $sale, ?Printer $printer = null): Printer
    {
        $printer = $this->resolve($printer, $sale->register);

        $this->send($printer, ReceiptDocument::render($sale, $printer));

        return $printer;
    }

    /**
     * Print the shift report — a peek while the drawer is open, the end of
     * sale once it is closed.
     *
     * @throws RuntimeException when there is no printer, or it will not take the job
     */
    public function shiftReport(DrawerSession $drawer, bool $showsExpected = true, ?Printer $printer = null): Printer
    {
        $printer = $this->resolve($printer, $drawer->register);

        $this->send($printer, ShiftReportDocument::render($drawer, $printer, $showsExpected));

        return $printer;
    }

    /**
     * Print the slip that proves the wiring.
     *
     * @throws RuntimeException when the printer will not take the job
     */
    public function testPage(Printer $printer): void
    {
        $bytes = TestPageDocument::render($printer);

        if ($printer->canPulse()) {
            /* The drawer is part of the wiring, so the test proves that too. */
            $bytes .= (new EscPos($printer->columns()))->pulse($printer->drawer_pin)->bytes();
        }

        $this->send($printer, $bytes);

        ActivityLog::record('print.test', $printer);
    }

    /**
     * Pop the drawer on its own, for a cashier who needs change.
     *
     * @throws RuntimeException when the printer cannot pop a drawer
     */
    public function openDrawer(Printer $printer): void
    {
        if (! $printer->canPulse()) {
            throw new RuntimeException(__(':name has no cash drawer wired to it.', ['name' => $printer->name]));
        }

        $this->send($printer, (new EscPos($printer->columns()))->pulse($printer->drawer_pin)->bytes());

        ActivityLog::record('open.drawer', $printer);
    }

    /**
     * Print a slip and pop the drawer the way a finished sale wants it, but
     * never at the cost of the sale. A jam, an unplugged cable or a share the
     * web server cannot see is a note in the log, not a failed bill.
     *
     * @return bool whether the slip actually went to a printer
     */
    public function afterSale(Sale $sale, bool $tookCash): bool
    {
        $printer = $this->printerFor($sale->register);

        if (! $printer) {
            return false;
        }

        $wantsSlip = (bool) Setting::read('receipt.auto_print');
        $wantsPulse = $tookCash && (bool) Setting::read('drawer.pulse_on_cash') && $printer->canPulse();

        if (! $wantsSlip && ! $wantsPulse) {
            return false;
        }

        $bytes = $wantsSlip ? ReceiptDocument::render($sale, $printer) : '';

        if ($wantsPulse) {
            $bytes .= (new EscPos($printer->columns()))->pulse($printer->drawer_pin)->bytes();
        }

        try {
            $this->send($printer, $bytes);
        } catch (RuntimeException $failure) {
            Log::warning('The receipt for '.$sale->invoiceNumber().' did not print.', [
                'sale_id' => $sale->id,
                'printer' => $printer->name,
                'reason' => $failure->getMessage(),
            ]);

            return false;
        }

        return $wantsSlip;
    }

    /**
     * Fall back to the counter's own printer when the caller did not name one.
     *
     * @throws RuntimeException when nothing is wired up
     */
    private function resolve(?Printer $printer, ?Register $register): Printer
    {
        $printer ??= $this->printerFor($register);

        if (! $printer) {
            throw new RuntimeException(__('No counter printer is set up, so this can only be printed from the browser.'));
        }

        return $printer;
    }

    /**
     * @throws RuntimeException when the printer will not take the job
     */
    private function send(Printer $printer, string $bytes): void
    {
        if ($bytes === '') {
            return;
        }

        $this->drivers->for($printer)->send($bytes);
    }
}
