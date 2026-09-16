<?php

namespace Tests\Unit;

use App\Support\SaleCalculator;
use PHPUnit\Framework\TestCase;

/**
 * The figures resources/js/sale-maths.js is held to as well. If one of these
 * changes, the till's copy has to change with it or the till and the server
 * will disagree about a bill.
 */
class SaleCalculatorTest extends TestCase
{
    public function test_gst_is_the_part_of_the_price_that_was_tax_when_prices_include_it(): void
    {
        $bill = SaleCalculator::calculate([
            ['qty' => '2', 'unit_price_paisa' => 11_800, 'tax_rate' => '18'],
        ]);

        $this->assertSame(23_600, $bill['subtotal_paisa']);
        $this->assertSame(3_600, $bill['tax_paisa']);
        $this->assertSame(23_600, $bill['total_paisa'], 'The shelf price is what the customer pays');
    }

    public function test_gst_is_added_on_top_when_prices_do_not_include_it(): void
    {
        $bill = SaleCalculator::calculate(
            [['qty' => '2', 'unit_price_paisa' => 11_800, 'tax_rate' => '18']],
            pricesIncludeTax: false,
        );

        $this->assertSame(4_248, $bill['tax_paisa']);
        $this->assertSame(27_848, $bill['total_paisa']);
        $this->assertSame(27_848, $bill['lines'][0]['line_total_paisa']);
    }

    public function test_a_zero_rated_line_carries_no_gst(): void
    {
        $bill = SaleCalculator::calculate([
            ['qty' => '1', 'unit_price_paisa' => 5_000, 'tax_rate' => null],
            ['qty' => '1', 'unit_price_paisa' => 5_000, 'tax_rate' => '0'],
        ], pricesIncludeTax: false);

        $this->assertSame(0, $bill['tax_paisa']);
        $this->assertSame(10_000, $bill['total_paisa']);
    }

    public function test_typed_discounts_are_rupees_unless_they_say_percent(): void
    {
        $this->assertSame(2_000, SaleCalculator::discountPaisa('20', 10_000));
        $this->assertSame(2_000, SaleCalculator::discountPaisa('Rs 20', 10_000));
        $this->assertSame(1_250, SaleCalculator::discountPaisa('12.50', 10_000));
        $this->assertSame(1_000, SaleCalculator::discountPaisa('10%', 10_000));
        $this->assertSame(333, SaleCalculator::discountPaisa('10%', 3_333), '333.3 rounds to 333');
        $this->assertSame(0, SaleCalculator::discountPaisa('', 10_000));
        $this->assertSame(0, SaleCalculator::discountPaisa(null, 10_000));
    }

    public function test_a_discount_never_comes_to_more_than_the_amount_it_comes_off(): void
    {
        $this->assertSame(10_000, SaleCalculator::discountPaisa('500', 10_000));
        $this->assertSame(10_000, SaleCalculator::discountPaisa('150%', 10_000));
        $this->assertSame(0, SaleCalculator::discountPaisa('50', 0));
    }

    public function test_a_line_discount_comes_off_before_gst_is_worked_out(): void
    {
        $bill = SaleCalculator::calculate(
            [['qty' => '1', 'unit_price_paisa' => 11_800, 'tax_rate' => '18', 'discount' => '18']],
        );

        $line = $bill['lines'][0];

        $this->assertSame(1_800, $line['discount_paisa']);
        $this->assertSame(10_000, $line['net_paisa']);
        $this->assertSame(1_525, $line['tax_paisa'], '10000 × 18 / 118 = 1525.4');
        $this->assertSame(10_000, $bill['total_paisa']);
    }

    public function test_a_bill_discount_is_shared_over_the_lines_and_adds_up_exactly(): void
    {
        $bill = SaleCalculator::calculate([
            'a' => ['qty' => '1', 'unit_price_paisa' => 10_000, 'tax_rate' => '0'],
            'b' => ['qty' => '1', 'unit_price_paisa' => 20_000, 'tax_rate' => '0'],
            'c' => ['qty' => '1', 'unit_price_paisa' => 3_333, 'tax_rate' => '0'],
        ], billDiscount: '100');

        $shares = array_column($bill['lines'], 'bill_discount_paisa');

        $this->assertSame(10_000, $bill['bill_discount_paisa']);
        $this->assertSame(10_000, array_sum($shares), 'No paisa lost or invented in the sharing');
        $this->assertSame(['a', 'b', 'c'], array_keys($bill['lines']), 'Lines keep their keys');
        $this->assertGreaterThan($bill['lines']['a']['bill_discount_paisa'], $bill['lines']['b']['bill_discount_paisa']);
        $this->assertSame(33_333 - 10_000, $bill['total_paisa']);
    }

    public function test_a_percentage_bill_discount_is_taken_after_the_line_discounts(): void
    {
        $bill = SaleCalculator::calculate([
            ['qty' => '1', 'unit_price_paisa' => 10_000, 'tax_rate' => '0', 'discount' => '10'],
            ['qty' => '1', 'unit_price_paisa' => 11_000, 'tax_rate' => '0'],
        ], billDiscount: '10%');

        $this->assertSame(1_000, $bill['line_discount_paisa']);
        $this->assertSame(2_000, $bill['bill_discount_paisa'], '10% of the 20000 left after line discounts');
        $this->assertSame(3_000, $bill['discount_paisa']);
        $this->assertSame(18_000, $bill['total_paisa']);
    }

    public function test_the_total_rounds_to_the_nearest_rupee_and_keeps_the_rounding_as_a_figure(): void
    {
        $bill = SaleCalculator::calculate(
            [['qty' => '1', 'unit_price_paisa' => 12_345, 'tax_rate' => '0']],
            roundToRupee: true,
        );

        $this->assertSame(12_345, $bill['exact_total_paisa']);
        $this->assertSame(45, $bill['round_off_paisa']);
        $this->assertSame(12_300, $bill['total_paisa']);

        $this->assertSame(49, SaleCalculator::roundOff(12_349));
        $this->assertSame(-50, SaleCalculator::roundOff(12_350), 'Half a rupee rounds up');
        $this->assertSame(0, SaleCalculator::roundOff(12_300));
    }

    public function test_weighed_quantities_are_exact_to_the_gram(): void
    {
        $bill = SaleCalculator::calculate([
            ['qty' => '0.75', 'unit_price_paisa' => 40_000, 'tax_rate' => '0'],
            ['qty' => '0.1', 'unit_price_paisa' => 40_000, 'tax_rate' => '0'],
            ['qty' => '0.333', 'unit_price_paisa' => 999, 'tax_rate' => '0'],
        ]);

        $this->assertSame(750, $bill['lines'][0]['qty_milli']);
        $this->assertSame(30_000, $bill['lines'][0]['gross_paisa']);
        $this->assertSame(4_000, $bill['lines'][1]['gross_paisa'], '0.1 kg is 100 g, not 99.999');
        $this->assertSame(333, $bill['lines'][2]['gross_paisa'], '332.667 rounds half-up');
    }

    public function test_part_of_a_pack_is_only_sellable_when_it_is_a_whole_number_of_pieces(): void
    {
        $this->assertTrue(SaleCalculator::isWholeInBase(500, 24), 'Half a box of 24 is 12');
        $this->assertSame(12, SaleCalculator::qtyBase(500, 24));
        $this->assertFalse(SaleCalculator::isWholeInBase(500, 1), 'Half a sachet is not');
        $this->assertFalse(SaleCalculator::isWholeInBase(250, 6), 'A quarter of 6 is 1.5');
        $this->assertTrue(SaleCalculator::isWholeInBase(3_000, 1));
    }

    public function test_decimals_are_read_from_their_digits_not_through_a_float(): void
    {
        $this->assertSame(1_250, SaleCalculator::scaled('1.25', 3));
        $this->assertSame(100, SaleCalculator::scaled('0.1', 3));
        $this->assertSame(500, SaleCalculator::scaled('.5', 3));
        $this->assertSame(1_234, SaleCalculator::scaled('1.2345', 3), 'Digits beyond the scale are dropped');
        $this->assertSame(1_800, SaleCalculator::scaled('18', 2));
        $this->assertSame(3_000, SaleCalculator::scaled(3, 3));

        $this->assertNull(SaleCalculator::scaled('abc', 3));
        $this->assertNull(SaleCalculator::scaled('-1', 3));
        $this->assertNull(SaleCalculator::scaled('', 3));
        $this->assertNull(SaleCalculator::scaled('.', 3));
    }
}
