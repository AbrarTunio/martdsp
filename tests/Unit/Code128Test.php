<?php

namespace Tests\Unit;

use App\Support\Code128;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The barcode drawing.
 *
 * Nothing here can be checked by looking at it — a label that prints beautifully
 * and scans as the wrong item is the failure mode — so the symbol table is
 * verified against the rules of the symbology, and every code is decoded back
 * out of the bars it produced.
 */
class Code128Test extends TestCase
{
    /**
     * The symbol table, read back out of the class so the tests can decode.
     *
     * @return array<int, string>
     */
    private function patterns(): array
    {
        return (new ReflectionClass(Code128::class))->getConstant('PATTERNS');
    }

    /**
     * Turn the drawn widths back into the symbol values they came from.
     *
     * @return array<int, int>
     */
    private function decode(string $widths): array
    {
        $reverse = array_flip($this->patterns());
        $symbols = [];

        /* Every symbol is six widths wide; only the stop symbol is seven. */
        while (strlen($widths) > 7) {
            $symbols[] = $reverse[substr($widths, 0, 6)] ?? -1;
            $widths = substr($widths, 6);
        }

        $symbols[] = $reverse[$widths] ?? -1;

        return $symbols;
    }

    public function test_the_symbol_table_holds_all_107_code_128_symbols(): void
    {
        $patterns = $this->patterns();

        $this->assertCount(107, $patterns);
        $this->assertSame(107, count(array_unique($patterns)), 'Two symbols sharing a pattern would decode ambiguously.');
    }

    /**
     * Every Code 128 symbol is three bars and three spaces totalling eleven
     * modules. The stop symbol alone carries a fourth bar, for thirteen.
     */
    public function test_every_symbol_is_eleven_modules_wide(): void
    {
        $patterns = $this->patterns();
        $stop = array_pop($patterns);

        foreach ($patterns as $value => $pattern) {
            $this->assertSame(6, strlen($pattern), "Symbol {$value} is not six bars and spaces.");
            $this->assertSame(11, array_sum(str_split($pattern)), "Symbol {$value} is not eleven modules wide.");
        }

        $this->assertSame('2331112', $stop);
        $this->assertSame(13, array_sum(str_split($stop)));
    }

    public function test_a_code_is_drawn_as_start_then_data_then_check_then_stop(): void
    {
        $symbols = $this->decode(Code128::widths('AB'));

        /* 'A' is 33 in subset B, 'B' is 34 — the value is the ASCII code less
           32 — and the check symbol is (104 + 33 + 34 × 2) % 103 = 102. */
        $this->assertSame([104, 33, 34, 102, 106], $symbols);
    }

    /**
     * The check symbol is the start value plus each data value times its
     * position, modulo 103. A scanner that disagrees reads nothing at all.
     */
    public function test_the_check_symbol_follows_the_modulo_103_rule(): void
    {
        $symbols = $this->decode(Code128::widths('SM-SURF01'));

        $this->assertSame(104, array_shift($symbols));
        $this->assertSame(106, array_pop($symbols));

        $check = array_pop($symbols);
        $expected = 104;

        foreach ($symbols as $position => $value) {
            $expected += $value * ($position + 1);
        }

        $this->assertSame($expected % 103, $check);
    }

    public function test_a_thirteen_digit_ean_survives_the_round_trip(): void
    {
        $code = '8964000101018';
        $symbols = $this->decode(Code128::widths($code));

        array_shift($symbols);
        array_pop($symbols);
        array_pop($symbols);

        $decoded = implode('', array_map(fn (int $value): string => chr($value + 32), $symbols));

        $this->assertSame($code, $decoded);
    }

    public function test_the_bars_always_start_and_end_with_a_bar(): void
    {
        $widths = Code128::widths('SM-4K9WZ2');

        /* Bars sit at even offsets, so an odd count of elements would end on
           a space and lose the last bar of the stop symbol. */
        $this->assertSame(1, strlen($widths) % 2);
    }

    public function test_an_empty_code_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Code128::widths('');
    }

    public function test_a_character_no_scanner_could_read_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Code128::widths("SM-\n01");
    }

    public function test_the_svg_is_sized_in_millimetres(): void
    {
        $svg = Code128::svg('8964000101018', 38.0, 12.0);

        $this->assertStringContainsString('width="38mm"', $svg);
        $this->assertStringContainsString('height="12mm"', $svg);
        $this->assertStringContainsString('shape-rendering="crispEdges"', $svg);
    }

    /**
     * Without the quiet zone a scanner run across a packed label sheet reads
     * nothing, so the first bar must never sit at the very edge.
     */
    public function test_the_svg_leaves_a_quiet_zone_on_each_side(): void
    {
        $code = '8964000101018';
        $svg = Code128::svg($code);
        $modules = array_sum(str_split(Code128::widths($code)));

        $this->assertStringContainsString('viewBox="0 0 '.($modules + 20).' 100"', $svg);
        $this->assertStringContainsString('<rect x="10"', $svg);
    }

    public function test_the_code_is_readable_to_a_screen_reader(): void
    {
        $this->assertStringContainsString('aria-label="8964000101018"', Code128::svg('8964000101018'));
    }

    public function test_printability_is_reported_before_a_sheet_is_drawn(): void
    {
        $this->assertTrue(Code128::isPrintable('8964000101018'));
        $this->assertTrue(Code128::isPrintable('SM-4K9WZ2'));
        $this->assertFalse(Code128::isPrintable(''));
        $this->assertFalse(Code128::isPrintable("SM-\n01"));
        $this->assertFalse(Code128::isPrintable('چائے'));
    }
}
