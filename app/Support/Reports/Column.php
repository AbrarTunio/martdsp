<?php

namespace App\Support\Reports;

use App\Support\Money;
use Carbon\CarbonInterface;

/**
 * One column of a report.
 *
 * Rows hold raw values — paisa, counts, dates — and the column turns them
 * into what a person reads on screen or paper, or what a spreadsheet can add
 * up. That way no report formats money itself, and a spreadsheet never gets
 * "Rs. 1,250.00" in a cell it is meant to total.
 */
final class Column
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly ColumnType $type = ColumnType::Text,
        public readonly bool $emphasis = false,
    ) {}

    public static function text(string $key, string $label, bool $emphasis = false): self
    {
        return new self($key, $label, ColumnType::Text, $emphasis);
    }

    public static function money(string $key, string $label, bool $emphasis = false): self
    {
        return new self($key, $label, ColumnType::Money, $emphasis);
    }

    public static function quantity(string $key, string $label): self
    {
        return new self($key, $label, ColumnType::Quantity);
    }

    public static function number(string $key, string $label, bool $emphasis = false): self
    {
        return new self($key, $label, ColumnType::Number, $emphasis);
    }

    public static function percent(string $key, string $label, bool $emphasis = false): self
    {
        return new self($key, $label, ColumnType::Percent, $emphasis);
    }

    public static function date(string $key, string $label): self
    {
        return new self($key, $label, ColumnType::Date);
    }

    public function isNumeric(): bool
    {
        return $this->type->isNumeric();
    }

    /**
     * Whether a value is below zero — a loss, a shortfall — and so drawn in red.
     */
    public function isNegative(mixed $value): bool
    {
        return $this->isNumeric() && is_numeric($value) && $value < 0;
    }

    /**
     * The value as a person reads it.
     */
    public function display(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return match ($this->type) {
            ColumnType::Money => Money::format((int) $value),
            ColumnType::Quantity => self::quantityFormat((float) $value),
            ColumnType::Number => number_format((int) $value),
            ColumnType::Percent => number_format((float) $value, 1).'%',
            ColumnType::Date => $value instanceof CarbonInterface ? $value->format('D, d M Y') : (string) $value,
            ColumnType::Text => (string) $value,
        };
    }

    /**
     * The value as a spreadsheet wants it: plain numbers, rupees rather than
     * paisa, dates as Y-m-d, and no text a spreadsheet could mistake for a
     * formula.
     */
    public function export(mixed $value): string|int|float
    {
        if ($value === null) {
            return '';
        }

        return match ($this->type) {
            ColumnType::Money => number_format(((int) $value) / 100, 2, '.', ''),
            ColumnType::Quantity => (float) $value,
            ColumnType::Number => (int) $value,
            ColumnType::Percent => round((float) $value, 1),
            ColumnType::Date => $value instanceof CarbonInterface ? $value->toDateString() : (string) $value,
            ColumnType::Text => self::defused((string) $value),
        };
    }

    private static function quantityFormat(float $value): string
    {
        $formatted = number_format($value, 3);

        return str_contains($formatted, '.') ? rtrim(rtrim($formatted, '0'), '.') : $formatted;
    }

    /**
     * A product named "=HYPERLINK(...)" must reach the spreadsheet as text.
     */
    private static function defused(string $value): string
    {
        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }
}
