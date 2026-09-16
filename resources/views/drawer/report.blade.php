{{--
    The shift report.

    Standalone like the receipt: no app chrome, inline styles, sized in
    millimetres for the thermal roll or an A4 page. While the drawer is open it
    is an X-report, a peek that closes nothing; once closed it is the Z-report,
    the end of sale. Under a blind count the cashier's copy leaves out every
    figure that would tell them what the drawer should hold.
--}}
@php
    use App\Enums\DrawerEntryType;
    use App\Support\Money;

    $session = $summary->session;
    $open = $session->isOpen();
    $isRoll = $paper !== 'a4';
    $rollWidth = $paper === '58' ? 58 : 80;
    $printWidth = $paper === '58' ? 48 : 72;
    $title = $open ? __('X-REPORT') : __('Z-REPORT');
    $gap = $summary->gapFromPreviousPaisa();
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} · {{ $session->register?->name }} · {{ $shop['name'] ?: config('app.name') }}</title>
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

        .report {
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

        .sections { display: block; }
        .page .sections { display: grid; grid-template-columns: 1fr 1fr; gap: 0 10mm; }
        .page .sections .rule { margin: 3mm 0; }

        .signatures { display: flex; gap: 6mm; margin-top: 10mm; }
        .signatures p { flex: 1; border-top: 1px solid #000; padding-top: 1mm; text-align: center; font-size: 0.9em; }

        @page {
            size: {{ $isRoll ? $rollWidth.'mm auto' : 'A4' }};
            margin: {{ $isRoll ? '0' : '10mm' }};
        }

        @media print {
            body { background: #fff; }
            .toolbar { display: none; }
            .report { margin: 0 auto; }
            .page { width: auto; max-width: none; padding: 0; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <p>{{ $title }} · {{ $session->register?->name }}</p>

        @foreach (['80' => '80 mm', '58' => '58 mm', 'a4' => 'A4'] as $option => $label)
            <a href="{{ route('drawer.report', ['drawer' => $session, 'paper' => $option]) }}" @class(['current' => $paper === $option])>{{ $label }}</a>
        @endforeach

        <a href="{{ route('drawer.show', $session) }}">{{ __('Drawer') }}</a>
        <button type="button" onclick="window.print()">{{ __('Print') }}</button>
    </div>

    <main @class(['report', 'roll' => $isRoll, 'page' => ! $isRoll])>
        <div class="center">
            <h1>{{ $shop['name'] ?: config('app.name') }}</h1>
            @if ($shop['address']) <p class="muted">{{ $shop['address'] }}</p> @endif
            @if ($shop['phone']) <p class="muted">{{ __('Ph') }} {{ $shop['phone'] }}</p> @endif
        </div>

        <p class="title">{{ $title }} — {{ $open ? __('NOT CLOSED') : __('END OF SALE') }}</p>

        <div class="row"><span class="strong">{{ $session->register?->name }}</span><span class="muted">{{ __('Shift #:id', ['id' => $session->id]) }}</span></div>
        <div class="row"><span>{{ __('Opened') }}</span><span class="nums">{{ $session->opened_at?->format('d/m/Y g:i A') }}</span></div>
        <div class="row muted"><span>{{ __('by') }}</span><span>{{ $session->opener?->name }}</span></div>
        @if (! $open)
            <div class="row"><span>{{ __('Closed') }}</span><span class="nums">{{ $session->closed_at?->format('d/m/Y g:i A') }}</span></div>
            <div class="row muted"><span>{{ __('by') }}</span><span>{{ $session->closer?->name }}</span></div>
        @endif
        <div class="row muted"><span>{{ __('Printed') }}</span><span class="nums">{{ now()->format('d/m/Y g:i A') }}</span></div>

        <hr class="rule">

        <div class="sections">
            <div>
                <h2>{{ __('Sales') }}</h2>
                <div class="row"><span>{{ __('Bills') }}</span><span class="nums">{{ number_format($summary->billCount) }}</span></div>
                @if ($seesExpected)
                    <div class="row strong"><span>{{ __('Total sold') }}</span><span class="nums">{{ Money::format($summary->billTotalPaisa) }}</span></div>
                    @if ($summary->discountPaisa > 0)
                        <div class="row"><span>{{ __('Discount given') }}</span><span class="nums">{{ Money::format($summary->discountPaisa) }}</span></div>
                    @endif
                    @if ($summary->taxPaisa > 0)
                        <div class="row"><span>{{ __('GST collected') }}</span><span class="nums">{{ Money::format($summary->taxPaisa) }}</span></div>
                    @endif
                @endif
                @if ($summary->voidedCount > 0)
                    <div class="row"><span>{{ __('Cancelled bills') }}</span><span class="nums">{{ $summary->voidedCount }}</span></div>
                @endif

                @if ($seesExpected && $summary->tenderLines())
                    <hr class="rule">
                    <h2>{{ __('Paid by') }}</h2>
                    @foreach ($summary->tenderLines() as $line)
                        <div class="row"><span>{{ __($line['method']->label()) }} ({{ $line['count'] }})</span><span class="nums">{{ Money::format($line['paisa']) }}</span></div>
                    @endforeach
                @endif

                <hr class="rule">

                <h2>{{ __('Cash drawer') }}</h2>
                @foreach ($summary->lines() as $line)
                    @continue(! $seesExpected && ! ($line['type']->isManual() || $line['type'] === DrawerEntryType::OpeningFloat))
                    <div class="row">
                        <span>{{ __($line['type']->label()) }}@if ($line['count'] > 1) ({{ $line['count'] }})@endif</span>
                        <span class="nums">{{ $line['type'] === DrawerEntryType::OpeningFloat ? '' : ($line['paisa'] < 0 ? '−' : '+') }}{{ Money::format(abs($line['paisa'])) }}</span>
                    </div>
                @endforeach
                @if ($seesExpected)
                    <div class="row total"><span>{{ __('Expected') }}</span><span class="nums">{{ Money::withSymbol($summary->expectedPaisa) }}</span></div>
                @endif
                @if ($gap !== null && $gap !== 0)
                    <p class="muted" style="margin-top: 1mm;">
                        {{ __('Opened with :amount :direction than the last shift left.', [
                            'amount' => Money::withSymbol(abs($gap)),
                            'direction' => $gap < 0 ? __('less') : __('more'),
                        ]) }}
                    </p>
                @endif
            </div>

            <div>
                @if ($isRoll) <hr class="rule"> @endif

                <h2>{{ __('Handled by hand') }}</h2>
                @forelse ($summary->handled as $entry)
                    <div class="row">
                        <span>{{ $entry->created_at?->format('g:i A') }} {{ __($entry->type->label()) }}</span>
                        <span class="nums">{{ $entry->amount_paisa < 0 ? '−' : '+' }}{{ Money::format(abs($entry->amount_paisa)) }}</span>
                    </div>
                    <p class="muted note">{{ $entry->user?->name }}@if ($entry->note) · {{ $entry->note }}@endif</p>
                @empty
                    <p class="muted">{{ __('No cash added or taken out.') }}</p>
                @endforelse

                @if (! $open)
                    <hr class="rule">

                    <h2>{{ __('Count') }}</h2>
                    @foreach ($session->countLines as $line)
                        <div class="row"><span class="nums">{{ number_format($line->denomination) }} × {{ $line->count }}</span><span class="nums">{{ Money::format($line->subtotal_paisa) }}</span></div>
                    @endforeach
                    <div class="row total"><span>{{ __('Counted') }}</span><span class="nums">{{ Money::withSymbol((int) $session->counted_cash_paisa) }}</span></div>
                    @if ($seesExpected && $session->variance_paisa !== null)
                        <div class="row strong">
                            <span>{{ $session->varianceLabel() }}</span>
                            <span class="nums">{{ $session->variance_paisa === 0 ? '' : ($session->variance_paisa < 0 ? '−' : '+') }}{{ Money::withSymbol(abs($session->variance_paisa)) }}</span>
                        </div>
                    @endif
                    @if ($session->variance_reason)
                        <p class="muted">{{ __('Reason:') }} {{ $session->variance_reason }}</p>
                    @endif
                    @if ($session->needs_approval)
                        <p class="muted">
                            {{ $session->approved_at
                                ? __('Signed off by :name', ['name' => $session->approver?->name])
                                : __('Waiting for a manager to sign off') }}
                        </p>
                    @endif

                    <hr class="rule">

                    <div class="row"><span>{{ __('Went to the safe') }}</span><span class="nums">{{ Money::format($summary->takenAwayPaisa()) }}</span></div>
                    <div class="row strong"><span>{{ __('Left for next shift') }}</span><span class="nums">{{ Money::format((int) $session->left_in_drawer_paisa) }}</span></div>
                @endif
            </div>
        </div>

        @if (! $open)
            <div class="signatures">
                <p>{{ __('Cashier') }}</p>
                <p>{{ __('Manager') }}</p>
            </div>
        @endif
    </main>

    @if ($autoPrint)
        <script>
            window.addEventListener('load', () => window.print());
        </script>
    @endif
</body>
</html>
