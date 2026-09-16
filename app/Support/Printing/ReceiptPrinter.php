<?php

namespace App\Support\Printing;

use RuntimeException;

/**
 * Something that can put ESC/POS bytes in front of a print head.
 *
 * Deliberately tiny. Everything about *what* to print is decided before this
 * point, so a driver only has to answer one question: can these bytes be
 * delivered, and if not, why not in words the shopkeeper can act on.
 */
interface ReceiptPrinter
{
    /**
     * Deliver the bytes, or explain what went wrong.
     *
     * @throws RuntimeException when the printer cannot be reached
     */
    public function send(string $bytes): void;

    /**
     * Where this driver is sending, for an error message or a log line.
     */
    public function describe(): string;
}
