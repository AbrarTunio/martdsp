<?php

namespace App\Support\Reports;

use Illuminate\Http\Request;

/**
 * A report the owner can open, print and download.
 *
 * A report only answers a question from the database. How it looks on the
 * phone, on paper and in a spreadsheet is the same for all of them — see
 * App\Http\Controllers\ReportController and resources/views/reports.
 *
 * To add one: extend this, then list it in App\Support\Reports\ReportRegistry.
 */
abstract class Report
{
    abstract public function title(): string;

    /**
     * One sentence: what question this answers.
     */
    abstract public function description(): string;

    /**
     * @param  array<string, string>  $filters  Already checked against filters().
     */
    abstract public function build(Period $period, array $filters): ReportResult;

    public function icon(): string
    {
        return 'chart';
    }

    /**
     * False for reports about how things stand right now, such as what the
     * stock is worth, which have no dates to pick.
     */
    public function usesPeriod(): bool
    {
        return true;
    }

    public function defaultPeriod(): string
    {
        return 'this_month';
    }

    /**
     * The choices above the table, each a short list. The option named as
     * the default — or else the first — is used when nothing has been picked.
     *
     * @return array<string, array{label: string, options: array<string, string>, default?: string}>
     */
    public function filters(): array
    {
        return [];
    }

    /**
     * The filter choices from the address, each one checked against its list
     * so that nothing else can reach a query.
     *
     * @return array<string, string>
     */
    final public function filterValues(Request $request): array
    {
        return collect($this->filters())
            ->map(function (array $filter, string $key) use ($request): string {
                $value = $request->query($key);

                return is_string($value) && array_key_exists($value, $filter['options'])
                    ? $value
                    : (string) ($filter['default'] ?? array_key_first($filter['options']));
            })
            ->all();
    }

    public function period(Request $request): Period
    {
        return $this->usesPeriod()
            ? Period::fromRequest($request, $this->defaultPeriod())
            : Period::preset('today');
    }
}
