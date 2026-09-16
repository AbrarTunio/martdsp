<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Setting;
use App\Support\Reports\Period;
use App\Support\Reports\Report;
use App\Support\Reports\ReportRegistry;
use App\Support\Reports\ReportResult;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The reports: one screen to pick from, and every report the same three
 * ways — on screen, printed on A4 (or saved as a PDF from the print box), and
 * downloaded for Excel.
 *
 * Reports show cost and profit, so they are for the owner and managers only.
 * What each one works out lives in App\Support\Reports.
 */
class ReportController extends Controller
{
    public function index(): View
    {
        Gate::authorize('see-financials');

        return view('reports.index', [
            'sections' => ReportRegistry::sections(),
        ]);
    }

    public function show(Request $request, string $key): View
    {
        [$report, $period, $filters, $result] = $this->run($request, $key);

        return view('reports.show', [
            'key' => $key,
            'report' => $report,
            'period' => $period,
            'filters' => $filters,
            'result' => $result,
            'query' => $this->query($report, $period, $filters),
        ]);
    }

    public function print(Request $request, string $key): View
    {
        [$report, $period, $filters, $result] = $this->run($request, $key);

        return view('reports.print', [
            'key' => $key,
            'report' => $report,
            'period' => $period,
            'filters' => $filters,
            'result' => $result,
            'query' => $this->query($report, $period, $filters),
            'shop' => [
                'name' => (string) Setting::read('shop.name'),
                'address' => (string) Setting::read('shop.address'),
                'ntn' => (string) Setting::read('shop.ntn'),
            ],
        ]);
    }

    /**
     * A CSV file, which Excel opens as a spreadsheet. The byte-order mark at
     * the start is what makes Excel read Urdu names correctly.
     */
    public function export(Request $request, string $key): StreamedResponse
    {
        [$report, $period, $filters, $result] = $this->run($request, $key);

        ActivityLog::record('report.exported', after: [
            'report' => $key,
            'from' => $report->usesPeriod() ? $period->from->toDateString() : null,
            'to' => $report->usesPeriod() ? $period->to->toDateString() : null,
        ] + $filters);

        $name = Str::slug($report->title()).'-'.($report->usesPeriod() ? $period->slug() : today()->toDateString()).'.csv';

        return response()->streamDownload(function () use ($result): void {
            $out = fopen('php://output', 'w');

            fwrite($out, "\xEF\xBB\xBF");

            foreach ($result->csvLines() as $line) {
                fputcsv($out, $line, escape: '');
            }

            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @return array{0: Report, 1: Period, 2: array<string, string>, 3: ReportResult}
     */
    private function run(Request $request, string $key): array
    {
        Gate::authorize('see-financials');

        $report = ReportRegistry::find($key) ?? abort(404);
        $period = $report->period($request);
        $filters = $report->filterValues($request);

        return [$report, $period, $filters, $report->build($period, $filters)];
    }

    /**
     * The address parameters that reopen the report exactly as it is, for
     * the print and download links.
     *
     * @param  array<string, string>  $filters
     * @return array<string, string>
     */
    private function query(Report $report, Period $period, array $filters): array
    {
        return ($report->usesPeriod() ? $period->query() : []) + $filters;
    }
}
