<?php

namespace Tests\Unit;

use App\Support\EscPos;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The byte builder behind every thermal slip.
 *
 * Nothing here needs a printer, which is the point: a receipt that comes out
 * crooked is almost always a column count that was wrong before the bytes
 * ever left the building, and that is what these tests pin down.
 */
class EscPosTest extends TestCase
{
    public function test_it_wakes_the_printer_and_sets_a_code_page(): void
    {
        $bytes = (new EscPos)->init()->bytes();

        $this->assertSame("\x1B@\x1Bt\x00", $bytes);
    }

    public function test_a_row_puts_the_figure_hard_against_the_right_edge(): void
    {
        $bytes = (new EscPos(32))->row('Sugar 1 kg', 'Rs. 210.00')->bytes();

        $this->assertSame(32, mb_strlen(rtrim($bytes, "\n")));
        $this->assertStringEndsWith("Rs. 210.00\n", $bytes);
        $this->assertStringStartsWith('Sugar 1 kg ', $bytes);
    }

    public function test_a_long_label_is_cut_rather_than_pushing_the_figure_off_the_roll(): void
    {
        $bytes = (new EscPos(32))
            ->row('Nestle Milkpak Full Cream Milk 1.5 Litre Carton', 'Rs. 1,450.00')
            ->bytes();

        $this->assertSame(32, mb_strlen(rtrim($bytes, "\n")));
        $this->assertStringEndsWith("Rs. 1,450.00\n", $bytes);
    }

    public function test_a_rule_fills_the_roll_exactly(): void
    {
        $this->assertSame(str_repeat('-', 42)."\n", (new EscPos(42))->rule()->bytes());
        $this->assertSame(str_repeat('=', 32)."\n", (new EscPos(32))->rule('=')->bytes());
    }

    public function test_a_long_name_wraps_on_spaces_and_can_be_indented(): void
    {
        $bytes = (new EscPos(20))->wrapped('Tapal Danedar Tea Bags', 2)->bytes();

        $this->assertSame(['  Tapal Danedar Tea', '  Bags'], explode("\n", rtrim($bytes, "\n")));
    }

    public function test_a_word_longer_than_the_roll_is_broken_rather_than_left_hanging(): void
    {
        $bytes = (new EscPos(10))->wrapped('ABCDEFGHIJKLMNO')->bytes();

        $this->assertSame(['ABCDEFGHIJ', 'KLMNO'], explode("\n", rtrim($bytes, "\n")));
    }

    public function test_centring_is_done_with_spaces_so_it_survives_a_left_aligned_block(): void
    {
        $bytes = (new EscPos(11))->centred('Thanks')->bytes();

        $this->assertSame("  Thanks\n", $bytes);
    }

    public function test_the_cut_feeds_the_paper_clear_of_the_head_first(): void
    {
        $this->assertSame("\x1Bd\x04\x1DV\x01", (new EscPos)->cut()->bytes());

        /* A printer with no cutter is told to feed nothing, not to feed four. */
        $this->assertSame("\x1DV\x01", (new EscPos)->cut(0)->bytes());
    }

    public function test_the_drawer_pulse_names_the_pin_it_is_wired_to(): void
    {
        $this->assertSame("\x1Bp\x00\x1E\xFF", (new EscPos)->pulse()->bytes());
        $this->assertSame("\x1Bp\x01\x1E\xFF", (new EscPos)->pulse(1)->bytes());
    }

    public function test_the_size_code_packs_width_into_the_high_nibble(): void
    {
        $this->assertSame("\x1D!\x00", (new EscPos)->size()->bytes());
        $this->assertSame("\x1D!\x01", (new EscPos)->size(1, 2)->bytes());
        $this->assertSame("\x1D!\x11", (new EscPos)->size(2, 2)->bytes());

        /* Eight is as far as the command goes, so ten is not sent as ten. */
        $this->assertSame("\x1D!\x77", (new EscPos)->size(9, 9)->bytes());
    }

    #[DataProvider('typography')]
    public function test_typographic_characters_are_reduced_to_what_the_head_can_form(string $given, string $expected): void
    {
        $this->assertSame($expected, EscPos::ascii($given));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function typography(): array
    {
        return [
            'en dash' => ['Rs. 100 – 200', 'Rs. 100 - 200'],
            'curly quote' => ['Shopkeeper’s copy', "Shopkeeper's copy"],
            'multiplication sign' => ['2 × 500 ml', '2 x 500 ml'],
            'rupee sign' => ['₨ 1,200', 'Rs. 1,200'],
            'middle dot' => ['Front · Sana', 'Front - Sana'],
        ];
    }

    public function test_urdu_is_dropped_rather_than_printed_as_rubbish(): void
    {
        $reduced = EscPos::ascii('Surf Excel سرف ایکسل');

        /* The English name is always there too, so a gap beats a row of ??? */
        $this->assertSame('Surf Excel', rtrim($reduced));
        $this->assertSame(1, preg_match('/^[ -~]*$/', $reduced));
    }

    public function test_line_breaks_survive_but_stray_carriage_returns_do_not(): void
    {
        $this->assertSame("one\ntwo", EscPos::ascii("one\r\ntwo"));
    }
}
