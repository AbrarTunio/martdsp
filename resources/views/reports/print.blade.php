{{--
    Every report on A4.

    Standalone like the receipt and the shift report: no app chrome, inline
    styles, sized in millimetres. A wide table turns the page on its side.
    "Save as PDF" in the browser's print box is how a report becomes a PDF —
    nothing extra has to be installed on the shop's computer for it.
--}}
@php
    $landscape = $result->isWide();
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $report->title() }} · {{ $report->usesPeriod() ? $period->label() : now()->format('d M Y') }} · {{ $shop['name'] ?: config('app.name') }}</title>
    <style>
        :root { color-scheme: light; }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: #f3f4f6;
            color: #000;
            font-family: system-ui, -apple-system, 'Segoe UI', Roboto, Arial, sans-serif;
        }

        .toolbar {
            position: sticky;
            top: 0;
            z-index: 1;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 8px;
            padding: 12px 16px;
            background: #111827;
            color: #fff;
            font-size: 13px;
        }

        .toolbar p { margin: 0; margin-right: auto; }

        .toolbar button,
        .toolbar a {
            padding: 9px 14px;
            border: 0;
            border-radius: 8px;
            background: #374151;
            color: #fff;
            font: inherit;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
        }

        .toolbar button { background: #059669; }

        .page {
            width: {{ $landscape ? '277mm' : '190mm' }};
            max-width: calc(100% - 32px);
            margin: 16px auto;
            padding: 12mm;
            background: #fff;
            font-size: {{ $landscape ? '8.5pt' : '9.5pt' }};
            line-height: 1.4;
        }

        h1 { margin: 0; font-size: 1.6em; }
        p { margin: 0; }
        .muted { color: #4b5563; }

        header {
            display: flex;
            justify-content: space-between;
            gap: 8mm;
            padding-bottom: 3mm;
            border-bottom: 2px solid #000;
        }

        header .right { text-align: right; }

        .stats {
            display: grid;
            grid-template-columns: repeat({{ max(1, min(4, count($result->stats))) }}, 1fr);
            gap: 3mm;
            margin: 4mm 0;
        }

        .stat { padding: 2.5mm 3mm; border: 1px solid #d1d5db; border-radius: 2mm; }
        .stat .label { font-size: 0.85em; color: #4b5563; }
        .stat .value { margin-top: 0.5mm; font-size: 1.35em; font-weight: 700; font-variant-numeric: tabular-nums; }
        .stat .hint { margin-top: 0.5mm; font-size: 0.8em; color: #4b5563; }

        table { width: 100%; border-collapse: collapse; }
        thead { display: table-header-group; }
        tfoot { display: table-row-group; }
        tr { break-inside: avoid; }

        th, td { padding: 1.4mm 2mm; border-bottom: 1px solid #e5e7eb; text-align: left; vertical-align: top; }
        thead th { border-bottom: 1.5px solid #000; font-size: 0.85em; text-transform: uppercase; letter-spacing: 0.03em; }
        tfoot td, tfoot th { border-top: 1.5px solid #000; border-bottom: 0; font-weight: 700; }

        .num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .strong { font-weight: 700; }
        .negative { color: #b91c1c; }
        .row-muted { color: #9ca3af; }
        .row-danger th { color: #b91c1c; }
        .row-warning th { color: #b45309; }
        .detail { display: block; font-weight: 400; font-size: 0.85em; color: #6b7280; }

        .footnote { margin-top: 4mm; font-size: 0.85em; color: #4b5563; }

        footer {
            display: flex;
            justify-content: space-between;
            margin-top: 6mm;
            padding-top: 2mm;
            border-top: 1px solid #d1d5db;
            font-size: 0.8em;
            color: #6b7280;
        }

        @page {
            size: A4 {{ $landscape ? 'landscape' : 'portrait' }};
            margin: 10mm;
        }

        @media print {
            body { background: #fff; }
            .toolbar { display: none; }
            .page { width: auto; max-width: none; margin: 0; padding: 0; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <p>{{ $report->title() }}</p>
        <a href="{{ route('reports.show', ['key' => $key] + $query) }}">{{ __('Back to the report') }}</a>
        <button type="button" onclick="window.print()">{{ __('Print or save as PDF') }}</button>
    </div>

    <main class="page">
        <header>
            <div>
                <h1>{{ $report->title() }}</h1>
                <p class="muted">
                    {{ $report->usesPeriod() ? $period->label() : __('As it stood at :time', ['time' => now()->format('d M Y, g:i A')]) }}
                    @foreach ($report->filters() as $name => $filter)
                        · {{ $filter['options'][$filters[$name]] ?? '' }}
                    @endforeach
                </p>
            </div>

            <div class="right">
                <p class="strong">{{ $shop['name'] ?: config('app.name') }}</p>
                @if ($shop['address']) <p class="muted">{{ $shop['address'] }}</p> @endif
                @if ($shop['ntn']) <p class="muted">{{ __('NTN') }} {{ $shop['ntn'] }}</p> @endif
            </div>
        </header>

        @if ($result->stats !== [])
            <div class="stats">
                @foreach ($result->stats as $stat)
                    <div class="stat">
                        <p class="label">{{ $stat['label'] }}</p>
                        <p class="value">{{ $stat['value'] }}</p>
                        @if (! empty($stat['hint'])) <p class="hint">{{ $stat['hint'] }}</p> @endif
                    </div>
                @endforeach
            </div>
        @endif

        @if ($result->isEmpty())
            <p class="muted" style="margin-top: 6mm;">{{ $result->emptyMessage ?? __('Nothing happened in these dates.') }}</p>
        @else
            <table>
                <thead>
                    <tr>
                        @foreach ($result->columns as $column)
                            <th @class(['num' => $column->isNumeric()])>{{ $column->label }}</th>
                        @endforeach
                    </tr>
                </thead>

                <tbody>
                    @foreach ($result->rows as $row)
                        <tr @class([
                            'row-muted' => ($row['_tone'] ?? null) === 'muted',
                            'row-danger' => ($row['_tone'] ?? null) === 'danger',
                            'row-warning' => ($row['_tone'] ?? null) === 'warning',
                        ])>
                            @foreach ($result->columns as $column)
                                @php $value = $row[$column->key] ?? null; @endphp

                                @if ($loop->first)
                                    <th>
                                        {{ $column->display($value) }}
                                        @if (! empty($row['_detail'])) <span class="detail">{{ $row['_detail'] }}</span> @endif
                                    </th>
                                @else
                                    <td @class([
                                        'num' => $column->isNumeric(),
                                        'strong' => $column->emphasis,
                                        'negative' => $column->isNegative($value),
                                    ])>{{ $column->display($value) }}</td>
                                @endif
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>

                @if ($result->totals !== [])
                    <tfoot>
                        <tr>
                            @foreach ($result->columns as $column)
                                @php $value = $result->totals[$column->key] ?? null; @endphp

                                @if ($loop->first)
                                    <th>{{ $value === null ? __('Total') : $column->display($value) }}</th>
                                @else
                                    <td @class(['num' => $column->isNumeric(), 'negative' => $column->isNegative($value)])>
                                        {{ $value === null ? '' : $column->display($value) }}
                                    </td>
                                @endif
                            @endforeach
                        </tr>
                    </tfoot>
                @endif
            </table>
        @endif

        @if ($result->footnote)
            <p class="footnote">{{ $result->footnote }}</p>
        @endif

        <footer>
            <span>{{ __('Printed :when by :name', ['when' => now()->format('d M Y, g:i A'), 'name' => auth()->user()->name]) }}</span>
            <span>{{ __('Amounts in rupees') }}</span>
        </footer>
    </main>
</body>
</html>
