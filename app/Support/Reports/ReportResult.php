<?php

namespace App\Support\Reports;

/**
 * What a report found: its table, the few headline figures above it, and a
 * chart if a picture says it faster.
 *
 * One shape for every report, so the screen, the A4 print and the
 * spreadsheet download are each written once.
 *
 * @phpstan-type Stat array{label: string, value: string, icon?: string, hint?: string|null, tone?: string}
 * @phpstan-type ChartDataset array{label: string, data: list<int|float>, color?: string, type?: string}
 * @phpstan-type ChartSpec array{type: string, labels: list<string>, datasets: list<ChartDataset>, money?: bool, horizontal?: bool}
 */
final class ReportResult
{
    /**
     * @param  list<Column>  $columns
     * @param  list<array<string, mixed>>  $rows  Raw values keyed by column key. A row may also carry `_link` (a URL for its first cell) and `_tone` ('danger', 'warning' or 'muted').
     * @param  array<string, mixed>  $totals  Raw values keyed by column key, for the last line of the table.
     * @param  list<Stat>  $stats
     * @param  ChartSpec|null  $chart
     */
    public function __construct(
        public readonly array $columns,
        public readonly array $rows,
        public readonly array $totals = [],
        public readonly array $stats = [],
        public readonly ?array $chart = null,
        public readonly ?string $footnote = null,
        public readonly ?string $emptyMessage = null,
    ) {}

    public function isEmpty(): bool
    {
        return $this->rows === [];
    }

    /**
     * Whether the table is wide enough to want an A4 page on its side.
     */
    public function isWide(): bool
    {
        return count($this->columns) > 7;
    }

    /**
     * The spreadsheet, line by line: the headings, every row, and the totals.
     *
     * @return iterable<int, list<string|int|float>>
     */
    public function csvLines(): iterable
    {
        yield array_map(fn (Column $column): string => $column->label, $this->columns);

        foreach ($this->rows as $row) {
            yield array_map(fn (Column $column): string|int|float => $column->export($row[$column->key] ?? null), $this->columns);
        }

        if ($this->totals !== []) {
            yield array_map(
                fn (Column $column, int $index): string|int|float => $index === 0 && ! array_key_exists($column->key, $this->totals)
                    ? __('Total')
                    : $column->export($this->totals[$column->key] ?? null),
                $this->columns,
                array_keys($this->columns),
            );
        }
    }
}
