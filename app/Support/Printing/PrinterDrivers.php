<?php

namespace App\Support\Printing;

use App\Enums\PrinterConnection;
use App\Models\Printer;
use RuntimeException;

/**
 * Picks the driver for a printer row.
 *
 * Kept as a class rather than a match inside the service so that a test can
 * bind a fake in its place and assert on the bytes that would have gone down
 * the wire, without a printer, a share, or a socket anywhere near it.
 */
class PrinterDrivers
{
    /**
     * @throws RuntimeException when the printer is not one the server drives
     */
    public function for(Printer $printer): ReceiptPrinter
    {
        if (! $printer->is_active) {
            throw new RuntimeException(__(':name is switched off.', ['name' => $printer->name]));
        }

        $target = trim((string) $printer->target);

        if ($printer->channel->isDirect() && $target === '') {
            throw new RuntimeException(__(':name has no :field saved yet.', [
                'name' => $printer->name,
                'field' => mb_strtolower(__($printer->channel->targetLabel() ?? __('address'))),
            ]));
        }

        return match ($printer->channel) {
            PrinterConnection::WindowsShare => new WindowsShareDriver($target),
            PrinterConnection::Network => $this->network($target),
            PrinterConnection::Browser => throw new RuntimeException(__(':name prints through the browser, so the server cannot send to it.', [
                'name' => $printer->name,
            ])),
        };
    }

    /**
     * Split "host:port", defaulting to the port every receipt printer uses.
     */
    private function network(string $target): NetworkDriver
    {
        $parts = explode(':', $target);
        $host = trim($parts[0]);
        $port = isset($parts[1]) ? (int) $parts[1] : 9100;

        if ($host === '' || $port < 1 || $port > 65535) {
            throw new RuntimeException(__(':target is not an address the printer can be reached at.', ['target' => $target]));
        }

        return new NetworkDriver($host, $port);
    }
}
