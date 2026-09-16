<?php

namespace Tests\Unit;

use App\Support\Packaging;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class PackagingTest extends TestCase
{
    /**
     * The worked example from the plan: base = sachet, 1 box = 24 sachets,
     * 1 carton = 12 boxes.
     *
     * @return array<int, array{unit_id: int, parent_unit_id: int|null, qty_per_parent: int}>
     */
    private function surfExcel(): array
    {
        return [
            ['unit_id' => 1, 'parent_unit_id' => null, 'qty_per_parent' => 1],
            ['unit_id' => 2, 'parent_unit_id' => 1, 'qty_per_parent' => 24],
            ['unit_id' => 3, 'parent_unit_id' => 2, 'qty_per_parent' => 12],
        ];
    }

    public function test_a_chain_of_packaging_flattens_to_base_units(): void
    {
        $this->assertSame(
            [1 => 1, 2 => 24, 3 => 288],
            Packaging::factors($this->surfExcel()),
        );
    }

    public function test_levels_may_be_listed_in_any_order(): void
    {
        $shuffled = array_reverse($this->surfExcel());

        $this->assertSame(288, Packaging::factors($shuffled)[3]);
    }

    public function test_a_single_level_product_is_its_own_base(): void
    {
        $factors = Packaging::factors([
            ['unit_id' => 7, 'parent_unit_id' => null, 'qty_per_parent' => 1],
        ]);

        $this->assertSame([7 => 1], $factors);
    }

    public function test_a_packaging_loop_is_refused(): void
    {
        $this->expectException(RuntimeException::class);

        Packaging::factors([
            ['unit_id' => 1, 'parent_unit_id' => 2, 'qty_per_parent' => 2],
            ['unit_id' => 2, 'parent_unit_id' => 1, 'qty_per_parent' => 3],
        ]);
    }

    public function test_a_parent_that_was_not_listed_is_refused(): void
    {
        $this->expectException(RuntimeException::class);

        Packaging::factors([
            ['unit_id' => 1, 'parent_unit_id' => 99, 'qty_per_parent' => 2],
        ]);
    }

    /**
     * @return array<int, array{factor: int, name: string}>
     */
    private function surfExcelLevels(): array
    {
        return [
            ['factor' => 1, 'name' => 'Sachet'],
            ['factor' => 24, 'name' => 'Box'],
            ['factor' => 288, 'name' => 'Carton'],
        ];
    }

    public function test_a_balance_is_broken_down_largest_first(): void
    {
        $this->assertSame(
            '4 cartons, 10 boxes, 21 sachets',
            Packaging::describe(1413, $this->surfExcelLevels()),
        );
    }

    public function test_levels_that_do_not_divide_in_are_left_out(): void
    {
        $this->assertSame('3 sachets', Packaging::describe(3, $this->surfExcelLevels()));
        $this->assertSame('1 box', Packaging::describe(24, $this->surfExcelLevels()));
    }

    public function test_an_empty_shelf_still_reads_in_the_smallest_unit(): void
    {
        $this->assertSame('0 sachets', Packaging::describe(0, $this->surfExcelLevels()));
    }

    /**
     * Negative stock is a real state while counts are being corrected, and it
     * has to be legible rather than silently shown as zero.
     */
    public function test_negative_stock_is_shown_as_negative(): void
    {
        $this->assertSame('−1 box, 1 sachet', Packaging::describe(-25, $this->surfExcelLevels()));
    }

    public function test_a_product_with_no_packaging_has_nothing_to_show(): void
    {
        $this->assertSame('—', Packaging::describe(10, []));
    }

    public function test_quantities_convert_up_to_base_units(): void
    {
        $this->assertSame(1440, Packaging::toBase(5, 288));
        $this->assertSame(3, Packaging::toBase(3, 1));
    }
}
