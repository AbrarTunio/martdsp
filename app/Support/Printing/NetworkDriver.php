<?php

namespace App\Support\Printing;

use RuntimeException;

/**
 * A thermal printer with its own network port, spoken to on raw TCP.
 *
 * Almost every network receipt printer listens on 9100 and takes ESC/POS
 * straight off the socket with no protocol around it. The timeouts are short
 * on purpose: a printer that has been unplugged must not hold the till up
 * while a customer waits.
 */
final class NetworkDriver implements ReceiptPrinter
{
    private const TIMEOUT_SECONDS = 3;

    public function __construct(
        private readonly string $host,
        private readonly int $port = 9100,
    ) {}

    public function send(string $bytes): void
    {
        $errorNumber = 0;
        $errorMessage = '';

        $socket = @fsockopen($this->host, $this->port, $errorNumber, $errorMessage, self::TIMEOUT_SECONDS);

        if ($socket === false) {
            throw new RuntimeException(__('Could not reach the printer at :target. :reason', [
                'target' => $this->describe(),
                'reason' => $errorMessage !== '' ? $errorMessage : __('Check that it is switched on and on the same network.'),
            ]));
        }

        try {
            stream_set_timeout($socket, self::TIMEOUT_SECONDS);

            if (@fwrite($socket, $bytes) === false) {
                throw new RuntimeException(__('The printer at :target accepted the connection but not the receipt.', [
                    'target' => $this->describe(),
                ]));
            }
        } finally {
            fclose($socket);
        }
    }

    public function describe(): string
    {
        return $this->host.':'.$this->port;
    }
}
