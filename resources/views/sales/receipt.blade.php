{{--
    The receipt.

    Standalone like the label sheet: no app chrome and inline styles, sized in
    millimetres so the browser's print dialog sends exactly one receipt to the
    USB thermal printer. The two roll widths get a narrow single column; A4
    gets a proper invoice for customers who need one for their own books.
    With ?print=1 it prints itself — the till loads it in a hidden frame.
--}}
@php
    use App\Support\Money;

    $isRoll = $paper !== 'a4';
    $rollWidth = $paper === '58' ? 58 : 80;
    $printWidth = $paper === '58' ? 48 : 72;
    $cashPayment = $sale->payments->first(fn ($payment) => $payment->method->isCash());
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $sale->invoiceNumber() }} · {{ $shop['name'] ?: config('app.name') }}</title>
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

        .receipt {
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
            line-height: 1.4;
        }

        .center { text-align: center; }
        .right { text-align: right; }
        .muted { color: #374151; }
        .strong { font-weight: 700; }
        .nums { font-variant-numeric: tabular-nums; white-space: nowrap; }

        h1 { margin: 0; font-size: 1.35em; }
        p { margin: 0; }

        .rule { border: 0; border-top: 1px dashed #000; margin: 2mm 0; }
        .page .rule { border-top-style: solid; border-color: #d1d5db; margin: 4mm 0; }

        .row { display: flex; justify-content: space-between; gap: 2mm; }
        .row > :first-child { min-width: 0; }

        .line { margin-bottom: 1.2mm; }
        .line .name { font-weight: 600; overflow-wrap: anywhere; }

        .total { font-size: 1.3em; font-weight: 800; }

        .void {
            margin: 2mm 0;
            padding: 1.5mm;
            border: 2px solid #000;
            font-weight: 800;
            text-align: center;
            letter-spacing: 0.1em;
        }

        table { width: 100%; border-collapse: collapse; }
        th { font-size: 0.85em; text-align: left; color: #374151; border-bottom: 1px solid #000; padding: 2mm 1mm; }
        td { padding: 2mm 1mm; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
        th.right, td.right { text-align: right; }

        .meta { display: grid; grid-template-columns: 1fr 1fr; gap: 1mm 8mm; }
        .totals { width: 80mm; margin-left: auto; }
        .totals .row { padding: 0.8mm 0; }

        @page {
            size: {{ $isRoll ? $rollWidth.'mm auto' : 'A4' }};
            margin: {{ $isRoll ? '0' : '10mm' }};
        }

        @media print {
            body { background: #fff; }
            .toolbar { display: none; }
            .receipt { margin: 0 auto; }
            .page { width: auto; max-width: none; padding: 0; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <p>{{ $sale->invoiceNumber() }}</p>

        @foreach (['80' => '80 mm', '58' => '58 mm', 'a4' => 'A4'] as $option => $label)
            <a href="{{ route('sales.receipt', ['sale' => $sale, 'paper' => $option]) }}" @class(['current' => $paper === $option])>{{ $label }}</a>
        @endforeach

        <a href="{{ route('sales.show', $sale) }}">{{ __('Bill') }}</a>
        <button type="button" onclick="window.print()">{{ __('Print') }}</button>
    </div>

    @if ($isRoll)
        <main class="receipt roll">
            <div class="center">
                <h1>{{ $shop['name'] ?: config('app.name') }}</h1>
                @if ($shop['address']) <p class="muted">{{ $shop['address'] }}</p> @endif
                @if ($shop['phone']) <p class="muted">{{ __('Ph') }} {{ $shop['phone'] }}</p> @endif
                @if ($shop['ntn']) <p class="muted">{{ __('NTN') }} {{ $shop['ntn'] }}</p> @endif
                @if ($shop['strn']) <p class="muted">{{ __('STRN') }} {{ $shop['strn'] }}</p> @endif
            </div>

            <hr class="rule">

            <div class="row"><span class="strong">{{ $sale->invoiceNumber() }}</span><span class="nums">{{ $sale->sold_at?->format('d/m/Y g:i A') }}</span></div>
            <div class="row muted">
                <span>{{ $sale->user?->name }}</span>
                <span>{{ $sale->register?->name }}</span>
            </div>
            @if ($sale->customer)
                <p>{{ __('Customer:') }} <span class="strong">{{ $sale->customerName() }}</span></p>
            @endif

            @if ($sale->isVoid())
                <p class="void">{{ __('CANCELLED') }}</p>
            @endif

            <hr class="rule">

            @foreach ($sale->items as $item)
                <div class="line">
                    <p class="name">{{ $item->name }}</p>
                    <div class="row">
                        <span class="muted">{{ $item->qtyForInput() }} {{ $item->unit_name }} × {{ Money::format($item->unit_price_paisa) }}</span>
                        <span class="nums strong">{{ Money::format($item->line_total_paisa) }}</span>
                    </div>
                    @if ($item->discount_paisa > 0)
                        <div class="row muted"><span>{{ __('discount') }}</span><span class="nums">−{{ Money::format($item->discount_paisa) }}</span></div>
                    @endif
                </div>
            @endforeach

            <hr class="rule">

            <div class="row"><span>{{ __('Items') }} ({{ $sale->items->count() }})</span><span class="nums">{{ Money::format($sale->subtotal_paisa) }}</span></div>
            @if ($sale->discount_paisa > 0)
                <div class="row"><span>{{ __('Discount') }}</span><span class="nums">−{{ Money::format($sale->discount_paisa) }}</span></div>
            @endif
            @if ($sale->tax_paisa > 0)
                <div class="row">
                    <span>{{ $sale->prices_include_tax ? __('GST included') : __('GST') }}</span>
                    <span class="nums">{{ $sale->prices_include_tax ? '' : '+' }}{{ Money::format($sale->tax_paisa) }}</span>
                </div>
            @endif
            @if ($sale->round_off_paisa !== 0)
                <div class="row"><span>{{ __('Rounded') }}</span><span class="nums">{{ $sale->round_off_paisa > 0 ? '+' : '−' }}{{ Money::format(abs($sale->round_off_paisa)) }}</span></div>
            @endif

            <div class="row total"><span>{{ __('TOTAL') }}</span><span class="nums">{{ Money::withSymbol($sale->total_paisa) }}</span></div>

            <hr class="rule">

            @foreach ($sale->payments as $payment)
                <div class="row">
                    <span>{{ $payment->method->label() }}@if ($payment->reference) <span class="muted">({{ $payment->reference }})</span>@endif</span>
                    <span class="nums">{{ Money::format($payment->method->isCash() ? $payment->tendered_paisa : $payment->amount_paisa) }}</span>
                </div>
            @endforeach
            @if ($sale->change_given_paisa > 0)
                <div class="row strong"><span>{{ __('Change') }}</span><span class="nums">{{ Money::format($sale->change_given_paisa) }}</span></div>
            @endif
            @if ($sale->due_paisa > 0)
                <div class="row strong"><span>{{ __('On khata') }}</span><span class="nums">{{ Money::format($sale->due_paisa) }}</span></div>
            @endif

            <hr class="rule">

            <div class="center">
                @if ($shop['footer']) <p>{{ $shop['footer'] }}</p> @endif
                <p class="muted">{{ __('Thank you for shopping with us') }}</p>
            </div>
        </main>
    @else
        <main class="receipt page">
            <div class="row">
                <div>
                    <h1>{{ $shop['name'] ?: config('app.name') }}</h1>
                    @if ($shop['address']) <p class="muted">{{ $shop['address'] }}</p> @endif
                    @if ($shop['phone']) <p class="muted">{{ __('Phone') }} {{ $shop['phone'] }}</p> @endif
                    @if ($shop['ntn']) <p class="muted">{{ __('NTN') }} {{ $shop['ntn'] }}</p> @endif
                    @if ($shop['strn']) <p class="muted">{{ __('STRN') }} {{ $shop['strn'] }}</p> @endif
                </div>
                <div class="right">
                    <p class="strong" style="font-size: 1.4em;">{{ $sale->tax_paisa > 0 ? __('Sales Tax Invoice') : __('Invoice') }}</p>
                    <p class="strong">{{ $sale->invoiceNumber() }}</p>
                    <p class="muted">{{ $sale->sold_at?->format('d M Y, g:i A') }}</p>
                </div>
            </div>

            @if ($sale->isVoid())
                <p class="void">{{ __('CANCELLED — :reason', ['reason' => $sale->void_reason]) }}</p>
            @endif

            <hr class="rule">

            <div class="meta">
                <p><span class="muted">{{ __('Bill to:') }}</span> <span class="strong">{{ $sale->customerName() }}</span></p>
                <p class="right"><span class="muted">{{ __('Served by:') }}</span> {{ $sale->user?->name }}</p>
                @if ($sale->customer?->phone)
                    <p><span class="muted">{{ __('Phone:') }}</span> {{ $sale->customer->phone }}</p>
                @endif
                <p class="right"><span class="muted">{{ __('Counter:') }}</span> {{ $sale->register?->name }}</p>
            </div>

            <table style="margin-top: 6mm;">
                <thead>
                    <tr>
                        <th style="width: 6%;">#</th>
                        <th>{{ __('Item') }}</th>
                        <th class="right">{{ __('Qty') }}</th>
                        <th class="right">{{ __('Price') }}</th>
                        <th class="right">{{ __('Discount') }}</th>
                        <th class="right">{{ __('GST') }}</th>
                        <th class="right">{{ __('Amount') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($sale->items as $item)
                        <tr>
                            <td class="muted">{{ $loop->iteration }}</td>
                            <td>{{ $item->name }}</td>
                            <td class="right nums">{{ $item->qtyForInput() }} {{ $item->unit_name }}</td>
                            <td class="right nums">{{ Money::format($item->unit_price_paisa) }}</td>
                            <td class="right nums">{{ $item->totalDiscountPaisa() > 0 ? Money::format($item->totalDiscountPaisa()) : '—' }}</td>
                            <td class="right nums">
                                @if ((float) $item->tax_rate > 0)
                                    {{ Money::format($item->tax_paisa) }}
                                    <span class="muted">({{ rtrim(rtrim((string) $item->tax_rate, '0'), '.') }}%)</span>
                                @else
                                    —
                                @endif
                            </td>
                            <td class="right nums strong">{{ Money::format($item->line_total_paisa) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <div class="totals" style="margin-top: 5mm;">
                <div class="row"><span>{{ __('Items') }}</span><span class="nums">{{ Money::withSymbol($sale->subtotal_paisa) }}</span></div>
                @if ($sale->discount_paisa > 0)
                    <div class="row"><span>{{ __('Discount') }}</span><span class="nums">− {{ Money::withSymbol($sale->discount_paisa) }}</span></div>
                @endif
                @if ($sale->tax_paisa > 0)
                    <div class="row">
                        <span>{{ $sale->prices_include_tax ? __('GST (included in prices)') : __('GST') }}</span>
                        <span class="nums">{{ $sale->prices_include_tax ? '' : '+ ' }}{{ Money::withSymbol($sale->tax_paisa) }}</span>
                    </div>
                @endif
                @if ($sale->round_off_paisa !== 0)
                    <div class="row"><span>{{ __('Rounded') }}</span><span class="nums">{{ $sale->round_off_paisa > 0 ? '+ ' : '− ' }}{{ Money::withSymbol(abs($sale->round_off_paisa)) }}</span></div>
                @endif
                <hr class="rule" style="margin: 2mm 0;">
                <div class="row total"><span>{{ __('Total') }}</span><span class="nums">{{ Money::withSymbol($sale->total_paisa) }}</span></div>

                <hr class="rule" style="margin: 2mm 0;">
                @foreach ($sale->payments as $payment)
                    <div class="row">
                        <span>{{ __('Paid by :method', ['method' => $payment->method->label()]) }}@if ($payment->reference) <span class="muted">({{ $payment->reference }})</span>@endif</span>
                        <span class="nums">{{ Money::withSymbol($payment->amount_paisa) }}</span>
                    </div>
                @endforeach
                @if ($cashPayment && $sale->change_given_paisa > 0)
                    <div class="row muted">
                        <span>{{ __('Cash handed over / change') }}</span>
                        <span class="nums">{{ Money::withSymbol($cashPayment->tendered_paisa) }} / {{ Money::withSymbol($sale->change_given_paisa) }}</span>
                    </div>
                @endif
                @if ($sale->due_paisa > 0)
                    <div class="row strong"><span>{{ __('Balance on account') }}</span><span class="nums">{{ Money::withSymbol($sale->due_paisa) }}</span></div>
                @endif
            </div>

            @if ($shop['footer'])
                <hr class="rule">
                <p class="center">{{ $shop['footer'] }}</p>
            @endif
        </main>
    @endif

    @if ($autoPrint)
        <script>
            window.addEventListener('load', () => window.print());
        </script>
    @endif
</body>
</html>
