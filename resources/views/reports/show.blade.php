{{--
    Every report on screen. The report itself only supplies figures — see
    App\Support\Reports — so this one view draws all of them the same way.
--}}
@php
    use App\Support\Reports\Period;

    $inputClass = 'tap-target rounded-md border-gray-300 text-sm shadow-xs dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300';

    $toneClass = fn (?string $tone): string => match ($tone) {
        'danger' => 'text-red-700 dark:text-red-400',
        'warning' => 'text-amber-700 dark:text-amber-400',
        default => '',
    };
@endphp

<x-app-layout :title="$report->title()">
    <x-flash />

    <x-page-header :title="$report->title()" :description="$report->description()">
        <x-ai-insight-button />

        <a href="{{ route('reports.export', ['key' => $key] + $query) }}"
           class="tap-target inline-flex items-center gap-1.5 rounded-lg border border-gray-300 px-4 text-sm font-medium hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800">
            <x-icon name="download" class="h-4.5 w-4.5" />
            {{ __('Excel') }}
        </a>

        <a href="{{ route('reports.print', ['key' => $key] + $query) }}" target="_blank" rel="noopener"
           class="tap-target inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white hover:bg-brand-700">
            <x-icon name="printer" class="h-4.5 w-4.5" />
            {{ __('Print') }}
        </a>
    </x-page-header>

    <div class="mt-3 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
        <a href="{{ route('reports.index') }}" class="font-medium text-brand-700 hover:underline dark:text-brand-400">{{ __('All reports') }}</a>
        <span aria-hidden="true">·</span>
        <span>
            {{ $report->usesPeriod()
                ? __('Showing :period', ['period' => $period->label()])
                : __('As it stands at :time today', ['time' => now()->format('g:i a')]) }}
        </span>
    </div>

    @if ($report->usesPeriod() || $report->filters() !== [])
        <form method="GET" action="{{ route('reports.show', $key) }}" class="mt-4"
              x-data x-on:change="$el.requestSubmit()">
            <div class="flex flex-wrap gap-2">
                @if ($report->usesPeriod())
                    <select name="period" aria-label="{{ __('Dates') }}" class="{{ $inputClass }}">
                        @foreach (Period::presets() as $value => $label)
                            <option value="{{ $value }}" @selected($period->preset === $value)>{{ $label }}</option>
                        @endforeach
                    </select>

                    @if ($period->preset === 'custom')
                        <input type="date" name="from" value="{{ $period->from->toDateString() }}" max="{{ today()->toDateString() }}"
                               aria-label="{{ __('From') }}" class="{{ $inputClass }}">
                        <input type="date" name="to" value="{{ $period->to->toDateString() }}" max="{{ today()->toDateString() }}"
                               aria-label="{{ __('To') }}" class="{{ $inputClass }}">
                    @endif
                @endif

                @foreach ($report->filters() as $name => $filter)
                    <select name="{{ $name }}" aria-label="{{ $filter['label'] }}" class="{{ $inputClass }}">
                        @foreach ($filter['options'] as $value => $label)
                            <option value="{{ $value }}" @selected(($filters[$name] ?? null) === (string) $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                @endforeach
            </div>

            <noscript>
                <button type="submit" class="tap-target mt-2 rounded-lg border border-gray-300 px-4 text-sm font-medium">{{ __('Show') }}</button>
            </noscript>
        </form>
    @endif

    @if ($result->stats !== [])
        <div class="mt-4 grid grid-cols-2 gap-2 sm:gap-3 lg:grid-cols-4">
            @foreach ($result->stats as $stat)
                <x-stat-card :label="$stat['label']" :value="$stat['value']" :icon="$stat['icon'] ?? null"
                             :hint="$stat['hint'] ?? null" :tone="$stat['tone'] ?? 'neutral'" />
            @endforeach
        </div>
    @endif

    @if ($result->isEmpty())
        <div class="mt-4">
            <x-empty-state icon="chart"
                           :title="__('Nothing to show')"
                           :description="$result->emptyMessage ?? __('Nothing happened in these dates. Try a longer stretch.')" />
        </div>
    @else
        @if ($result->chart)
            <x-card class="mt-4">
                <x-chart :spec="$result->chart" :label="$report->title()" :height="($result->chart['horizontal'] ?? false) ? 'h-80' : 'h-64'" />
            </x-card>
        @endif

        <div class="mt-4 overflow-x-auto rounded-xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
            <table class="w-full text-sm">
                <thead class="border-b border-gray-200 text-left text-xs text-gray-500 dark:border-gray-800 dark:text-gray-400">
                    <tr>
                        @foreach ($result->columns as $column)
                            <th scope="col" @class([
                                'px-3 py-2.5 font-medium whitespace-nowrap',
                                'text-right' => $column->isNumeric(),
                                'sticky left-0 z-10 bg-white pl-4 dark:bg-gray-900' => $loop->first,
                            ])>{{ $column->label }}</th>
                        @endforeach
                    </tr>
                </thead>

                <tbody>
                    @foreach ($result->rows as $row)
                        @php $tone = $row['_tone'] ?? null; @endphp

                        <tr @class([
                            'border-b border-gray-100 last:border-b-0 dark:border-gray-800',
                            'text-gray-400 dark:text-gray-600' => $tone === 'muted',
                        ])>
                            @foreach ($result->columns as $column)
                                @php $value = $row[$column->key] ?? null; @endphp

                                @if ($loop->first)
                                    <th scope="row" @class([
                                        'sticky left-0 z-10 max-w-[14rem] bg-white py-2.5 pr-3 pl-4 text-left font-medium dark:bg-gray-900',
                                        $toneClass($tone),
                                    ])>
                                        @if (! empty($row['_link']))
                                            <a href="{{ $row['_link'] }}" class="hover:underline">{{ $column->display($value) }}</a>
                                        @else
                                            {{ $column->display($value) }}
                                        @endif

                                        @if (! empty($row['_detail']))
                                            <span class="block truncate text-xs font-normal text-gray-500 dark:text-gray-400">{{ $row['_detail'] }}</span>
                                        @endif
                                    </th>
                                @else
                                    <td @class([
                                        'px-3 py-2.5 whitespace-nowrap',
                                        'text-right tabular-nums' => $column->isNumeric(),
                                        'font-semibold' => $column->emphasis,
                                        'text-money-out' => $column->isNegative($value),
                                    ])>{{ $column->display($value) }}</td>
                                @endif
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>

                @if ($result->totals !== [])
                    <tfoot class="border-t-2 border-gray-200 font-semibold dark:border-gray-700">
                        <tr>
                            @foreach ($result->columns as $column)
                                @php $value = $result->totals[$column->key] ?? null; @endphp

                                @if ($loop->first)
                                    <th scope="row" class="sticky left-0 z-10 bg-white py-2.5 pr-3 pl-4 text-left dark:bg-gray-900">
                                        {{ $value === null ? __('Total') : $column->display($value) }}
                                    </th>
                                @else
                                    <td @class([
                                        'px-3 py-2.5 whitespace-nowrap',
                                        'text-right tabular-nums' => $column->isNumeric(),
                                        'text-money-out' => $column->isNegative($value),
                                    ])>{{ $value === null ? '' : $column->display($value) }}</td>
                                @endif
                            @endforeach
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    @endif

    @if ($result->footnote)
        <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">{{ $result->footnote }}</p>
    @endif

    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
        {{ __('Print makes an A4 page — choose "Save as PDF" in the print box to keep a copy. Excel downloads a spreadsheet of the table.') }}
    </p>
</x-app-layout>
