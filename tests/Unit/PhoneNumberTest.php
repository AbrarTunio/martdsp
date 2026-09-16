<?php

namespace Tests\Unit;

use App\Support\PhoneNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every way a Pakistani number gets written down, and the one way it is
 * stored. Two spellings of the same number are two accounts for one man, so
 * this is what keeps the shop from having them.
 */
class PhoneNumberTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function theSameNumberWrittenManyWays(): array
    {
        return [
            'as it is typed into the box' => ['3343401969', '+923343401969'],
            'as it is dialled at home' => ['03343401969', '+923343401969'],
            'with the code already on it' => ['+923343401969', '+923343401969'],
            'with the code and no plus' => ['923343401969', '+923343401969'],
            'as it is dialled from abroad' => ['00923343401969', '+923343401969'],
            'written with spaces and dashes' => ['0334-340 1969', '+923343401969'],
            'a city landline' => ['021-3455 1200', '+922134551200'],
            'a small town landline' => ['051 2345678', '+92512345678'],
        ];
    }

    #[DataProvider('theSameNumberWrittenManyWays')]
    public function test_a_number_is_stored_one_way_however_it_was_typed(string $typed, string $stored): void
    {
        $this->assertSame($stored, PhoneNumber::normalise($typed));
        $this->assertTrue(PhoneNumber::isValid(PhoneNumber::normalise($typed)));
    }

    public function test_an_empty_box_stays_empty(): void
    {
        $this->assertNull(PhoneNumber::normalise(null));
        $this->assertNull(PhoneNumber::normalise('   '));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function numbersThatAreNotPakistani(): array
    {
        return [
            'too short' => ['12345'],
            'too long' => ['33434019691234'],
            'a foreign number' => ['+441632960123'],
            'letters' => ['ring the shop'],
        ];
    }

    #[DataProvider('numbersThatAreNotPakistani')]
    public function test_anything_that_is_not_a_pakistani_number_is_refused(string $typed): void
    {
        $this->assertFalse(PhoneNumber::isValid(PhoneNumber::normalise($typed)));
    }

    public function test_the_stored_number_can_be_put_back_in_the_box_and_read_out_loud(): void
    {
        $this->assertSame('3343401969', PhoneNumber::national('+923343401969'));
        $this->assertSame('+92 334 3401969', PhoneNumber::forHumans('+923343401969'));
    }

    /**
     * A number that was already bad is handed back as it was, so the person
     * who typed it can see what they typed and fix it.
     */
    public function test_a_number_that_cannot_be_read_is_left_alone(): void
    {
        $this->assertSame('ring the shop', PhoneNumber::normalise('  ring the shop  '));
        $this->assertSame('ring the shop', PhoneNumber::national('ring the shop'));
        $this->assertSame('ring the shop', PhoneNumber::forHumans('ring the shop'));
    }
}
