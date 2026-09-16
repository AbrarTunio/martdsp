@props(['code', 'width' => 38, 'height' => 12, 'showCode' => true])

{{--
    A Code 128 barcode drawn as inline SVG — no image library and no network
    call, so a label sheet prints the same on a counter PC with no internet.
    The human-readable line underneath is what gets typed when a label is
    scuffed and will not scan.
--}}
@php
    $code = trim((string) $code);
    $printable = \App\Support\Code128::isPrintable($code);
@endphp

<span {{ $attributes->class('inline-flex flex-col items-center leading-none') }}>
    @if ($printable)
        {!! \App\Support\Code128::svg($code, (float) $width, (float) $height) !!}

        @if ($showCode)
            <span class="mt-0.5 font-mono text-[0.5rem] tracking-[0.15em]">{{ $code }}</span>
        @endif
    @else
        <span class="text-[0.6rem] text-red-600">{{ __('Cannot print this code') }}</span>
    @endif
</span>
