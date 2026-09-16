<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Draws a Code 128 barcode as an SVG.
 *
 * Shelf and pack labels have to come off the same machine the shop already
 * owns, so this renders with nothing but the bundled fonts and an <svg> tag —
 * no image library, no font file, no external service. Code 128 subset B is
 * used because it covers the whole printable ASCII range, which is what a
 * hand-made SKU like "SM-4K9WZ2" needs; a 13-digit EAN prints through it
 * unchanged too.
 *
 * @see https://en.wikipedia.org/wiki/Code_128 for the symbology.
 */
class Code128
{
    /**
     * Bar and space widths for each of the 107 symbols, read left to right,
     * starting with a bar. Index 104 starts subset B, 106 stops.
     *
     * @var array<int, string>
     */
    private const PATTERNS = [
        '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
        '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
        '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211',
        '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
        '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
        '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
        '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
        '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
        '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
        '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141',
        '114131', '311141', '411131', '211412', '211214', '211232', '2331112',
    ];

    private const START_B = 104;

    private const STOP = 106;

    /**
     * The bar widths for a code, as one string of digits.
     *
     * @throws InvalidArgumentException when the code is empty or holds a
     *                                  character subset B cannot encode
     */
    public static function widths(string $code): string
    {
        if ($code === '') {
            throw new InvalidArgumentException('A barcode needs a code to draw.');
        }

        $values = [];

        foreach (str_split($code) as $character) {
            $value = ord($character) - 32;

            if ($value < 0 || $value > 94) {
                throw new InvalidArgumentException("This barcode cannot be printed: \"{$character}\" is not a character a Code 128 scanner reads.");
            }

            $values[] = $value;
        }

        $checksum = self::START_B;

        foreach ($values as $position => $value) {
            $checksum += $value * ($position + 1);
        }

        $symbols = array_merge([self::START_B], $values, [$checksum % 103, self::STOP]);

        return implode('', array_map(fn (int $symbol): string => self::PATTERNS[$symbol], $symbols));
    }

    /**
     * An SVG of the barcode, sized in millimetres so it prints at a known
     * physical width whatever the printer's DPI is.
     *
     * A quiet zone of ten module widths is left on each side: without it a
     * scanner run across a densely packed label sheet reads nothing.
     */
    public static function svg(string $code, float $widthMm = 38.0, float $heightMm = 12.0): string
    {
        $widths = str_split(self::widths($code));
        $modules = array_sum(array_map('intval', $widths));
        $quietZone = 10;
        $total = $modules + (2 * $quietZone);

        $bars = '';
        $x = $quietZone;
        $isBar = true;

        foreach ($widths as $width) {
            $width = (int) $width;

            if ($isBar) {
                $bars .= sprintf('<rect x="%d" y="0" width="%d" height="100" />', $x, $width);
            }

            $x += $width;
            $isBar = ! $isBar;
        }

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %d 100" width="%smm" height="%smm" '
                .'preserveAspectRatio="none" shape-rendering="crispEdges" fill="#000" role="img" aria-label="%s">%s</svg>',
            $total,
            rtrim(rtrim(number_format($widthMm, 2, '.', ''), '0'), '.'),
            rtrim(rtrim(number_format($heightMm, 2, '.', ''), '0'), '.'),
            htmlspecialchars($code, ENT_QUOTES),
            $bars,
        );
    }

    /**
     * Whether a code can be drawn at all, so a label sheet can skip an item
     * rather than fail the whole print job.
     */
    public static function isPrintable(string $code): bool
    {
        return $code !== '' && preg_match('/^[\x20-\x7E]+$/', $code) === 1;
    }
}
