{{--
    The label sheet.

    Deliberately standalone: no app chrome, no Tailwind, no web font. Labels
    have to come out at an exact physical size on whatever the shop has
    plugged in, so everything here is sized in millimetres and the styles are
    inline rather than built.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Labels') }} · {{ config('app.name') }}</title>
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
            gap: 12px;
            padding: 12px 16px;
            background: #111827;
            color: #fff;
            font-size: 13px;
        }

        .toolbar button,
        .toolbar a {
            padding: 9px 16px;
            border: 0;
            border-radius: 8px;
            font: inherit;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
        }

        .toolbar button { background: #059669; color: #fff; }
        .toolbar a { background: #374151; color: #fff; }
        .toolbar p { margin: 0; margin-right: auto; }

        .sheet {
            display: flex;
            flex-wrap: wrap;
            gap: 0;
            width: {{ $size['columns'] * $size['width'] }}mm;
            margin: 16px auto;
            background: #fff;
        }

        .label {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            width: {{ $size['width'] }}mm;
            height: {{ $size['height'] }}mm;
            padding: 2mm;
            overflow: hidden;
            text-align: center;
            page-break-inside: avoid;
            break-inside: avoid;
        }

        .label .name {
            width: 100%;
            overflow: hidden;
            font-size: 8pt;
            font-weight: 600;
            line-height: 1.15;
            white-space: nowrap;
            text-overflow: ellipsis;
        }

        .label .unit {
            width: 100%;
            overflow: hidden;
            font-size: 6.5pt;
            color: #374151;
            white-space: nowrap;
            text-overflow: ellipsis;
        }

        .label .price {
            margin-top: 0.5mm;
            font-size: 10pt;
            font-weight: 700;
        }

        .label svg { display: block; margin-top: 0.8mm; }

        .label .code {
            font-family: ui-monospace, 'Courier New', monospace;
            font-size: 6pt;
            letter-spacing: 0.12em;
        }

        .label .unprintable { font-size: 6.5pt; color: #b91c1c; }

        .empty { padding: 48px 16px; text-align: center; color: #6b7280; }

        @page {
            size: {{ $size['columns'] > 1 ? 'A4' : $size['width'].'mm '.$size['height'].'mm' }};
            margin: {{ $size['columns'] > 1 ? '5mm' : '0' }};
        }

        @media print {
            body { background: #fff; }
            .toolbar { display: none; }
            .sheet { width: auto; margin: 0; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <p>
            {{ trans_choice('{0}Nothing to print|{1}1 label|[2,*]:count labels', count($labels), ['count' => count($labels)]) }}
            · {{ $size['width'] }}&times;{{ $size['height'] }} mm
            · {{ __('print at 100% scale with margins off') }}
        </p>

        <a href="{{ url()->previous() }}">{{ __('Back') }}</a>
        <button type="button" onclick="window.print()">{{ __('Print') }}</button>
    </div>

    @if ($labels === [])
        <p class="empty">{{ __('No labels were asked for. Go back and enter how many you need.') }}</p>
    @else
        <div class="sheet">
            @foreach ($labels as $label)
                <div class="label">
                    <span class="name">{{ $label['name'] }}</span>
                    <span class="unit">{{ $label['unit'] }}</span>

                    @if ($showPrice)
                        <span class="price">{{ \App\Support\Money::withSymbol($label['price']) }}</span>
                    @endif

                    @if ($label['printable'])
                        {!! \App\Support\Code128::svg($label['code'], $size['width'] - 8, $size['height'] / 3) !!}
                        <span class="code">{{ $label['code'] }}</span>
                    @else
                        <span class="unprintable">{{ __('This code cannot be printed as a barcode.') }}</span>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</body>
</html>
