<?php

namespace App\Enums;

/**
 * How the server reaches a receipt printer.
 *
 * A thermal printer is not really a printer to us — it is a pipe that accepts
 * ESC/POS bytes. Windows exposes a USB printer as a share; a network model
 * listens on a socket; and a counter with neither still prints, through the
 * browser's own dialog, which is the only option on a phone.
 */
enum PrinterConnection: string
{
    case WindowsShare = 'windows_share';
    case Network = 'network';
    case Browser = 'browser';

    public function label(): string
    {
        return match ($this) {
            self::WindowsShare => 'USB printer shared from this PC',
            self::Network => 'Network printer with an IP address',
            self::Browser => 'Through the browser print dialog',
        };
    }

    public function hint(): string
    {
        return match ($this) {
            self::WindowsShare => 'Share the printer in Windows, then give its share name here. This is the only way the cash drawer can be popped.',
            self::Network => 'For printers with an Ethernet or Wi-Fi port. Port 9100 unless the manual says otherwise.',
            self::Browser => 'No setup, works from a phone, but Windows decides the margins and the cash drawer cannot be opened.',
        };
    }

    /**
     * Whether the server sends the bytes itself. A browser printer is driven
     * by whichever device is looking at the till, so the server does nothing.
     */
    public function isDirect(): bool
    {
        return $this !== self::Browser;
    }

    /**
     * Whether a cash drawer can be wired to it. The pulse is a few ESC/POS
     * bytes down the same cable, so it needs a direct connection.
     */
    public function canPulse(): bool
    {
        return $this->isDirect();
    }

    /**
     * What the target field holds, so the form can label it honestly.
     */
    public function targetLabel(): ?string
    {
        return match ($this) {
            self::WindowsShare => 'Share name',
            self::Network => 'Address and port',
            self::Browser => null,
        };
    }

    public function targetPlaceholder(): string
    {
        return match ($this) {
            self::WindowsShare => '\\localhost\THERMAL',
            self::Network => '192.168.1.50:9100',
            self::Browser => '',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $connection): array => [$connection->value => __($connection->label())])
            ->all();
    }
}
