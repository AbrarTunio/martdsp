@php
    use App\Support\KhataAging;
    use App\Support\Money;

    $owed = array_sum($totals);

    // The cards across the top always answer for the whole khata; the table's
    // last row answers only for the rows above it, which a bucket filter cuts.
    $shown = KhataAging::totals(array_intersect_key($agings, $customers->keyBy('id')->all()));
    $shownOwed = array_sum($shown);
@endphp

<x-app-layout :title="__('How old is the khata money')">
    <x-flash />

    <x-page-header :title="__('How old is the money')"
                   :description="__('A khata that keeps moving costs nothing. What hurts is the money that stopped moving — so this is sorted by the longest wait, not the largest amount.')">
        <x-ai-insight-button />

        <a href="{{ route('customers.index') }}"
           class="tap-target inline-flex items-center rounded-lg border border-gray-300 px-4 text-sm font-medium hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800">
            {{ __('All khatas') }}
        </a>

        <button type="button" onclick="window.print()"
                class="tap-target inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white hover:bg-brand-700 print:hidden">
            <x-icon name="printer" class="h-4.5 w-4.5" />
            {{ __('Print') }}
        </button>
    </x-page-header>

    <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
        {{ __(':shop · printed :when · khata is due after :days', [
            'shop' => $shop['name'] ?: config('app.name'),
            'when' => $printedAt->format('d M Y, g:i A'),
            'days' => trans_choice(':count day|:count days', $creditDays, ['count' => $creditDays]),
        ]) }}
    </p>

    <div class="mt-4 grid grid-cols-2 gap-2 sm:gap-3 lg:grid-cols-6">
        <x-stat-card :label="__('Owed in total')" :value="Money::rounded($owed)" icon="book"
                     :tone="$owed > 0 ? 'warning' : 'neutral'" />

        @foreach (KhataAging::BUCKETS as $key)
            <x-stat-card :label="$labels[$key]" :value="Money::rounded($totals[$key])"
                         :icon="$key === 'current' ? 'clock' : 'alert'"
                         :tone="$totals[$key] > 0 ? $tones[$key] : 'neutral'" />
        @endforeach
    </div>

    <div class="mt-4 flex flex-wrap gap-2 print:hidden">
        @foreach (array_merge(['all' => __('Everyone')], $labels) as $key => $label)
            <a href="{{ route('customers.aging', $key === 'all' ? [] : ['bucket' => $key]) }}"
               @class([
                   'tap-target inline-flex items-center rounded-lg border px-3 text-sm font-medium',
                   'border-brand-500 bg-brand-50 text-brand-700 dark:border-brand-500/50 dark:bg-brand-500/10 dark:text-brand-300' => $bucket === $key,
                   'border-gray-300 hover:bg-gray-100 dark:border-gray-700 dark:hover:bg-gray-800' => $bucket !== $key,
               ])>
                {{ $label }}
            </a>
        @endforeach
    </div>

    @if ($customers->isEmpty())
        <div class="mt-4">
            <x-empty-state icon="check"
                           :title="$bucket === 'all' ? __('Nobody owes you anything') : __('Nothing sitting in that bucket')"
                           :description="__('Every khata is clear. This is the page you want to look boring.')" />
        </div>
    @else
        <div class="mt-4 overflow-x-auto rounded-xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
            <table class="w-full min-w-[46rem] text-sm">
                <thead class="border-b border-gray-200 text-left text-xs text-gray-500 dark:border-gray-800 dark:text-gray-400">
                    <tr>
                        <th class="px-4 py-2.5 font-medium">{{ __('Customer') }}</th>
                        @foreach (KhataAging::BUCKETS as $key)
                            <th class="px-3 py-2.5 text-right font-medium">{{ $labels[$key] }}</th>
                        @endforeach
                        <th class="px-4 py-2.5 text-right font-medium">{{ __('Total') }}</th>
                    </tr>
                </thead>

                <tbody>
                    @foreach ($customers as $customer)
                        @php $aging = $agings[$customer->id]; @endphp

                        <tr class="border-b border-gray-100 last:border-b-0 dark:border-gray-800">
                            <td class="px-4 py-2.5">
                                <a href="{{ route('customers.show', $customer) }}"
                                   class="font-medium text-brand-700 hover:underline dark:text-brand-400">{{ $customer->name }}</a>
                                <p class="text-xs text-gray-500 dark:text-gray-400">
                                    {{ $customer->phone ? \App\Support\PhoneNumber::forHumans($customer->phone) : __('No phone') }}
                                    @if ($aging->isOverdue())
                                        · {{ trans_choice('late :count day|late :count days', $aging->daysLate(), ['count' => $aging->daysLate()]) }}
                                    @endif
                                </p>
                            </td>

                            @foreach (KhataAging::BUCKETS as $key)
                                <td @class([
                                    'px-3 py-2.5 text-right tabular-nums',
                                    'text-gray-300 dark:text-gray-700' => $aging->paisa($key) === 0,
                                    'font-medium text-money-out' => $aging->paisa($key) > 0 && $tones[$key] === 'danger',
                                ])>
                                    {{ $aging->paisa($key) === 0 ? '—' : Money::format($aging->paisa($key)) }}
                                </td>
                            @endforeach

                            <td class="px-4 py-2.5 text-right font-semibold tabular-nums">
                                {{ Money::format($aging->owedPaisa) }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>

                <tfoot class="border-t-2 border-gray-200 font-semibold dark:border-gray-700">
                    <tr>
                        <td class="px-4 py-2.5">{{ trans_choice(':count customer|:count customers', $customers->count(), ['count' => $customers->count()]) }}</td>
                        @foreach (KhataAging::BUCKETS as $key)
                            <td class="px-3 py-2.5 text-right tabular-nums">{{ Money::format($shown[$key]) }}</td>
                        @endforeach
                        <td class="px-4 py-2.5 text-right tabular-nums">{{ Money::format($shownOwed) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
            {{ __('The buckets count from the day each purchase was due, and a payment always clears the oldest purchase first.') }}
        </p>
    @endif
</x-app-layout>
