{{--
    A Chart.js chart. `spec` is the small description built on the server —
    see App\Support\Reports\ReportResult and resources/js/charts.js.
--}}
@props(['spec', 'label' => '', 'height' => 'h-64'])

<div {{ $attributes->class(['relative', $height]) }} x-data="chart(@js($spec))" role="img" aria-label="{{ $label }}">
    <canvas x-ref="canvas"></canvas>
</div>
