<?php

namespace App\Support\Printing;

use RuntimeException;

/**
 * A USB thermal printer reached through a Windows printer share.
 *
 * Windows will not let a process write to a USB printer port directly, but it
 * will copy a file to a shared printer, which is exactly what `copy /b` does.
 * The bytes go to a temporary file first and are copied in binary mode, so
 * nothing helpfully converts a 0x1A into an end-of-file on the way.
 *
 * If Apache is running as a Windows service under LocalSystem it has its own
 * session and cannot see a share the logged-in user mapped. That shows up
 * here as "cannot find the path", which is why the message says where to
 * look rather than just reporting a failure.
 */
final class WindowsShareDriver implements ReceiptPrinter
{
    public function __construct(private readonly string $target) {}

    public function send(string $bytes): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            throw new RuntimeException(__('A shared Windows printer can only be reached from the PC it is shared on.'));
        }

        if (! function_exists('exec')) {
            throw new RuntimeException(__('PHP is not allowed to run commands on this server, so it cannot reach the printer.'));
        }

        $file = tempnam(sys_get_temp_dir(), 'receipt');

        if ($file === false || file_put_contents($file, $bytes) === false) {
            throw new RuntimeException(__('The receipt could not be written to a temporary file.'));
        }

        try {
            $output = [];
            $status = 0;

            exec(sprintf('cmd /c copy /b %s %s 2>&1', escapeshellarg($file), escapeshellarg($this->target)), $output, $status);

            if ($status !== 0) {
                throw new RuntimeException(__('Windows would not send anything to :target. :reason', [
                    'target' => $this->target,
                    'reason' => $this->explain(implode(' ', $output)),
                ]));
            }
        } finally {
            @unlink($file);
        }
    }

    public function describe(): string
    {
        return $this->target;
    }

    /**
     * Turn what Windows said into something worth acting on.
     */
    private function explain(string $output): string
    {
        $said = trim($output);

        return match (true) {
            str_contains($said, 'cannot find the path'),
            str_contains($said, 'network path was not found') => __('Check the share name, and that the printer is shared and switched on. If the web server runs as a Windows service it cannot see shares you mapped as yourself.'),
            str_contains($said, 'Access is denied') => __('Windows refused permission. Share the printer for Everyone, or run the web server as the logged-in user.'),
            $said === '' => __('Windows gave no reason.'),
            default => $said,
        };
    }
}
