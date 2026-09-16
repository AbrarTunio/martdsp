@props(['paisa', 'rounded' => false, 'signed' => false, 'symbol' => true])

@php
    use App\Support\Money;

    $paisa = (int) $paisa;

    $text = match (true) {
        $rounded => Money::rounded(abs($paisa)),
        $symbol => Money::withSymbol(abs($paisa)),
        default => Money::format(abs($paisa)),
    };

    if ($signed && $paisa !== 0) {
        $text = ($paisa > 0 ? '+' : '−').' '.$text;
    } elseif ($paisa < 0) {
        $text = '−'.' '.$text;
    }
@endphp

<span {{ $attributes->class([
    'whitespace-nowrap tabular-nums',
    'text-money-in' => $signed && $paisa > 0,
    'text-money-out' => $paisa < 0,
]) }}>{{ $text }}</span>
