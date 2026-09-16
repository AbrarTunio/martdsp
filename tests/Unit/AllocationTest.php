<?php

namespace Tests\Unit;

use App\Support\Allocation;
use PHPUnit\Framework\TestCase;

/**
 * A bill's discount and tax are shared over its lines before any item's cost
 * is known. The one rule that matters: the shares add back up to the paisa.
 */
class AllocationTest extends TestCase
{
    public function test_an_amount_is_shared_in_proportion(): void
    {
        $this->assertSame(
            ['a' => 100, 'b' => 200, 'c' => 300],
            Allocation::spread(600, ['a' => 1000, 'b' => 2000, 'c' => 3000]),
        );
    }

    public function test_the_paisa_left_by_rounding_go_to_the_largest_remainders(): void
    {
        /* 83.33, 166.67 and 250 — the spare paisa goes to the 166.67. */
        $shares = Allocation::spread(500, [10 => 1000, 20 => 2000, 30 => 3000]);

        $this->assertSame([10 => 83, 20 => 167, 30 => 250], $shares);
        $this->assertSame(500, array_sum($shares));
    }

    public function test_the_shares_always_add_up_exactly(): void
    {
        $weights = [1 => 333_33, 2 => 1, 3 => 7_777, 4 => 250_000, 5 => 19];

        foreach ([1, 7, 99, 1_001, 123_457, 9_999_999] as $amount) {
            $this->assertSame($amount, array_sum(Allocation::spread($amount, $weights)), "Sharing {$amount}");
        }
    }

    public function test_ties_go_to_the_line_that_came_first(): void
    {
        $this->assertSame([4, 3, 3], Allocation::spread(10, [5, 5, 5]));
    }

    public function test_an_amount_over_nothing_is_shared_evenly(): void
    {
        $this->assertSame([4, 3, 3], Allocation::spread(10, [0, 0, 0]));
    }

    public function test_a_negative_amount_keeps_its_sign(): void
    {
        $shares = Allocation::spread(-500, [1000, 2000, 3000]);

        $this->assertSame(-500, array_sum($shares));
        $this->assertSame([-83, -167, -250], $shares);
    }

    public function test_no_lines_means_no_shares(): void
    {
        $this->assertSame([], Allocation::spread(500, []));
    }

    public function test_nothing_to_share_gives_every_line_zero(): void
    {
        $this->assertSame([7 => 0, 8 => 0], Allocation::spread(0, [7 => 100, 8 => 300]));
    }
}
