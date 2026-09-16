{{--
    A customer's khata on paper.

    Standalone like the receipt and the shift report: no app chrome, inline
    styles, sized in millimetres for a thermal roll or an A4 page. The roll is
    for "yeh raha hisaab" over the counter; A4 is for a customer who wants
    something to take away.
--}}
@php
    use App\Support\Money;

    $isRoll = $paper !== 'a4';
    $rollWidth = $paper === '58' ? 58 : 80;
    $printWidth = $paper === '58' ? 48 : 72;
    $balance = (int) $customer->balance_paisa;
    $running = $broughtForward;
    $period = match (true) {
        (bool) ($from && $to) => __(':from to :to', [
            'from' => \Illuminate\Support\Carbon::parse($from)->format('d/m/Y'),
            'to' => \Illuminate\Support\Carbon::parse($to)->format('d/m/Y'),
        ]),
        (bool) $from => __('From :from', ['from' => \Illuminate\Support\Carbon::parse($from)->format('d/m/Y')]),
        (bool) $to => __('Up to :to', ['to' => \Illuminate\Support\Carbon::parse($to)->format('d/m/Y')]),
        default => __('Everything'),
    };
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Khata') }} · {{ $customer->name }} · {{ $shop['name'] ?: config('app.name') }}</title>
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
        .toolbar a.current { outline: 2px solid #34d399; }

        .statement {
            margin: 16px auto;
            background: #fff;
        }

        .roll {
            width: {{ $printWidth }}mm;
            padding: 3mm 0;
            font-size: {{ $paper === '58' ? '8.5pt' : '9.5pt' }};
            line-height: 1.3;
        }

        .page {
            width: 190mm;
            max-width: calc(100% - 32px);
            padding: 12mm;
            font-size: 10pt;
            line-height: 1.45;
        }

        .center { text-align: center; }
        .muted { color: #374151; }
        .strong { font-weight: 700; }
        .nums { font-variant-numeric: tabular-nums; white-space: nowrap; }

        h1 { margin: 0; font-size: 1.35em; }
        h2 { margin: 0 0 1mm; font-size: 1em; text-transform: uppercase; letter-spacing: 0.06em; }
        p { margin: 0; }

        .rule { border: 0; border-top: 1px dashed #000; margin: 2mm 0; }
        .page .rule { border-top-style: solid; border-color: #d1d5db; margin: 4mm 0; }

        .row { display: flex; justify-content: space-between; gap: 2mm; }
        .row > :first-child { min-width: 0; overflow-wrap: anywhere; }

        .title {
            margin: 2mm 0;
            padding: 1.5mm;
            border: 2px solid #000;
            font-weight: 800;
            text-align: center;
            letter-spacing: 0.1em;
        }

        .total { font-size: 1.2em; font-weight: 800; }
        .note { padding-left: 3mm; font-size: 0.9em; }
        .urdu { font-size: 1.1em; direction: rtl; }

        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; font-size: 0.85em; text-transform: uppercase; letter-spacing: 0.04em; }
        th, td { padding: 1.5mm 1mm; vertical-align: top; }
        .page thead tr { border-bottom: 1px solid #000; }
        .page tbody tr { border-bottom: 1px solid #e5e7eb; }
        .page tfoot tr { border-top: 2px solid #000; font-weight: 800; }
        .right { text-align: right; }

        .signatures { display: flex; gap: 6mm; margin-top: 10mm; }
        .signatures p { flex: 1; border-top: 1px solid #000; padding-top: 1mm; text-align: center; font-size: 0.9em; }

        @page {
            size: {{ $isRoll ? $rollWidth.'mm auto' : 'A4' }};
            margin: {{ $isRoll ? '0' : '10mm' }};
        }

        @media print {
            body { background: #fff; }
            .toolbar { display: none; }
            .statement { margin: 0 auto; }
            .page { width: auto; max-width: none; padding: 0; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <p>{{ __('Khata') }} · {{ $customer->name }}</p>

        @foreach (['80' => '80 mm', '58' => '58 mm', 'a4' => 'A4'] as $option => $label)
            <a href="{{ route('customers.statement', ['customer' => $customer, 'paper' => $option, 'from' => $from, 'to' => $to]) }}"
               @class(['current' => $paper === $option])>{{ $label }}</a>
        @endforeach

        <a href="{{ route('customers.show', $customer) }}">{{ __('Khata page') }}</a>
        <button type="button" onclick="window.print()">{{ __('Print') }}</button>
    </div>

    <main @class(['statement', 'roll' => $isRoll, 'page' => ! $isRoll])>
        <div class="center">
            <h1>{{ $shop['name'] ?: config('app.name') }}</h1>
            @if ($shop['address']) <p class="muted">{{ $shop['address'] }}</p> @endif
            @if ($shop['phone']) <p class="muted">{{ __('Ph') }} {{ $shop['phone'] }}</p> @endif
        </div>

        <p class="title">{{ __('KHATA STATEMENT') }}</p>

        <div class="row"><span class="strong">{{ $customer->name }}</span><span class="muted nums">{{ \App\Support\PhoneNumber::forHumans($customer->phone) }}</span></div>
        @if ($customer->name_ur)
            <p class="urdu strong">{{ $customer->name_ur }}</p>
        @endif
        @if ($customer->address) <p class="muted">{{ $customer->address }}</p> @endif
        <div class="row muted"><span>{{ __('Period') }}</span><span>{{ $period }}</span></div>
        <div class="row muted"><span>{{ __('Printed') }}</span><span class="nums">{{ now()->format('d/m/Y g:i A') }}</span></div>

        <hr class="rule">

        @if ($isRoll)
            @if ($from)
                <div class="row strong"><span>{{ __('Brought forward') }}</span><span class="nums">{{ Money::format($broughtForward) }}</span></div>
                <hr class="rule">
            @endif

            @forelse ($entries as $entry)
                @php $running += $entry->changePaisa(); @endphp

                <div class="row">
                    <span>{{ $entry->entry_date->format('d/m/y') }} {{ __($entry->type->label()) }}</span>
                    <span class="nums">{{ $entry->changePaisa() >= 0 ? '+' : '−' }}{{ Money::format(abs($entry->changePaisa())) }}</span>
                </div>
                <div class="row muted note">
                    <span>{{ $entry->note ?: ($entry->method?->label() ?? '') }}</span>
                    <span class="nums">{{ Money::format($running) }}</span>
                </div>
            @empty
                <p class="muted center">{{ __('Nothing on the khata for this period.') }}</p>
            @endforelse
        @else
            <table>
                <thead>
                    <tr>
                        <th>{{ __('Date') }}</th>
                        <th>{{ __('What happened') }}</th>
                        <th class="right">{{ __('Taken') }}</th>
                        <th class="right">{{ __('Paid') }}</th>
                        <th class="right">{{ __('Balance') }}</th>
                    </tr>
                </thead>

                <tbody>
                    @if ($from)
                        <tr>
                            <td class="nums">{{ \Illuminate\Support\Carbon::parse($from)->format('d/m/Y') }}</td>
                            <td class="strong">{{ __('Brought forward') }}</td>
                            <td class="right"></td>
                            <td class="right"></td>
                            <td class="right nums strong">{{ Money::format($broughtForward) }}</td>
                        </tr>
                    @endif

                    @forelse ($entries as $entry)
                        @php $running += $entry->changePaisa(); @endphp

                        <tr>
                            <td class="nums">{{ $entry->entry_date->format('d/m/Y') }}</td>
                            <td>
                                {{ __($entry->type->label()) }}
                                @if ($entry->method) <span class="muted">· {{ __($entry->method->label()) }}</span> @endif
                                @if ($entry->note) <p class="muted">{{ $entry->note }}</p> @endif
                            </td>
                            <td class="right nums">{{ $entry->debit_paisa > 0 ? Money::format($entry->debit_paisa) : '' }}</td>
                            <td class="right nums">{{ $entry->credit_paisa > 0 ? Money::format($entry->credit_paisa) : '' }}</td>
                            <td class="right nums">{{ Money::format($running) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="center muted">{{ __('Nothing on the khata for this period.') }}</td>
                        </tr>
                    @endforelse
                </tbody>

                <tfoot>
                    <tr>
                        <td colspan="2">{{ __('Closing balance') }}</td>
                        <td class="right nums">{{ Money::format($entries->sum('debit_paisa')) }}</td>
                        <td class="right nums">{{ Money::format($entries->sum('credit_paisa')) }}</td>
                        <td class="right nums">{{ Money::format($running) }}</td>
                    </tr>
                </tfoot>
            </table>
        @endif

        <hr class="rule">

        <div class="row total">
            <span>{{ $balance >= 0 ? __('Balance owed') : __('Advance with us') }}</span>
            <span class="nums">{{ Money::withSymbol(abs($balance)) }}</span>
        </div>

        @if ($aging->isOverdue())
            <p class="muted">
                {{ __(':amount of this is past due, the oldest by :days.', [
                    'amount' => Money::withSymbol($aging->overduePaisa()),
                    'days' => trans_choice(':count day|:count days', $aging->daysLate(), ['count' => $aging->daysLate()]),
                ]) }}
            </p>
        @endif

        @if (! $isRoll && $aging->owedPaisa > 0)
            <hr class="rule">

            <h2>{{ __('How old the money is') }}</h2>
            @foreach ($aging->lines() as $line)
                @continue($line['paisa'] === 0)
                <div class="row"><span>{{ $line['label'] }}</span><span class="nums">{{ Money::format($line['paisa']) }}</span></div>
            @endforeach
        @endif

        <p class="center muted" style="margin-top: 3mm;">
            {{ __('Please check this against your own record and tell us at once if anything differs.') }}
        </p>

        @unless ($isRoll)
            <div class="signatures">
                <p>{{ __('Customer') }}</p>
                <p>{{ $shop['name'] ?: config('app.name') }}</p>
            </div>
        @endunless
    </main>

    @if ($autoPrint)
        <script>
            window.addEventListener('load', () => window.print());
        </script>
    @endif
</body>
</html>
