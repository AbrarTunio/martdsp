<?php

namespace App\Support\Reports;

/**
 * What a report column holds, which decides how it is shown on screen, how
 * it prints, and what goes into the spreadsheet.
 */
enum ColumnType: string
{
    case Text = 'text';
    case Money = 'money';
    case Quantity = 'quantity';
    case Number = 'number';
    case Percent = 'percent';
    case Date = 'date';

    /**
     * Figures line up on the right so the rupees sit under each other.
     */
    public function isNumeric(): bool
    {
        return ! in_array($this, [self::Text, self::Date], true);
    }
}
