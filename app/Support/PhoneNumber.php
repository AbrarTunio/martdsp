<?php

namespace App\Support;

/**
 * Pakistani phone numbers, written down one way.
 *
 * The same salesman is 0300-1234567 in one person's hand, +92 300 1234567 in
 * another's and 00923001234567 in a third. Stored as they were typed, those
 * are three different suppliers, and the shop ends up with three accounts for
 * one man. So every number entered anywhere is put into one shape here first:
 * +92 followed by the number without the 0 in front.
 *
 * Nine or ten digits are allowed after the code, which covers mobiles
 * (3001234567) and landlines in both the big cities (2134551200) and the
 * small ones (512345678).
 */
class PhoneNumber
{
    /** The only country this shop's suppliers and customers are in. */
    public const COUNTRY_CODE = '+92';

    /** What a stored number must look like, for the validator. */
    public const PATTERN = '/^\+92[1-9][0-9]{8,9}$/';

    /**
     * Put a typed number into its one true shape, or answer null when there
     * is nothing usable in it. Anything that is not a Pakistani number is
     * handed back trimmed, so the validator can say so in plain words rather
     * than this quietly mangling it.
     */
    public static function normalise(?string $input): ?string
    {
        $typed = trim((string) $input);

        if ($typed === '') {
            return null;
        }

        $national = self::digits($typed);

        return self::looksNational($national) ? self::COUNTRY_CODE.$national : $typed;
    }

    public static function isValid(?string $input): bool
    {
        return is_string($input) && preg_match(self::PATTERN, $input) === 1;
    }

    /**
     * The part the shopkeeper types into the box beside the +92 — the number
     * without the 0 in front.
     */
    public static function national(?string $stored): string
    {
        $stored = trim((string) $stored);

        if ($stored === '') {
            return '';
        }

        $national = self::digits($stored);

        return self::looksNational($national) ? $national : $stored;
    }

    /**
     * For reading out loud: "+92 300 1234567".
     */
    public static function forHumans(?string $stored): string
    {
        if (! self::isValid($stored)) {
            return trim((string) $stored);
        }

        $national = substr((string) $stored, 3);

        return self::COUNTRY_CODE.' '.substr($national, 0, 3).' '.substr($national, 3);
    }

    /**
     * Strip the digits out, then take off whichever way of writing the
     * country was used: 0092, +92, 92, or the plain 0 dialled from inside
     * Pakistan. Whatever is left is the part that goes in the box beside the
     * +92, however much of it has been typed so far — which is also what
     * searching runs on, so a cashier who types 0300 finds +92300… .
     */
    public static function digits(?string $typed): string
    {
        $typed = (string) $typed;

        $digits = preg_replace('/\D/', '', $typed) ?? '';

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        if (str_starts_with($digits, '0')) {
            return substr($digits, 1);
        }

        /* 92 at the front is only the country code when what follows is long
           enough to be a whole number on its own. */
        if (str_starts_with($digits, '92') && strlen($digits) >= 11) {
            return substr($digits, 2);
        }

        return $digits;
    }

    private static function looksNational(string $digits): bool
    {
        return preg_match('/^[1-9][0-9]{8,9}$/', $digits) === 1;
    }
}
