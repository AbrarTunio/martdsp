<x-app-layout :title="__('Dashboard')">
    <x-flash />

    <x-page-header
        :title="__('Good to see you, :name', ['name' => Str::before(auth()->user()->name, ' ')])"
        :description="now()->format('l, j F Y')">
        <x-ai-insight-button />

        @can('see-financials')
            <a href="{{ route('reports.index') }}"
               class="tap-target inline-flex items-center gap-1.5 rounded-lg border border-gray-300 px-3 text-sm font-medium hover:bg-gray-100 dark:border-gray-700 dark:hover:bg-gray-800">
                <x-icon name="chart" class="h-4 w-4 text-gray-500 dark:text-gray-400" />
                {{ __('Reports') }}
            </a>
        @endcan

        <a href="{{ route('pos.index') }}"
           class="tap-target inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white hover:bg-brand-700">
            <x-icon name="cart" class="h-4.5 w-4.5" />
            {{ __('Sell') }}
        </a>
    </x-page-header>

    <div class="mt-5 space-y-6" x-data>
        @if ($financials)
            <section x-rise>
                <h2 class="text-xs font-semibold tracking-wide text-gray-500 uppercase dark:text-gray-400">{{ __('Today') }}</h2>

                <div class="mt-2 grid grid-cols-2 gap-2 sm:gap-3 lg:grid-cols-4">
                    @foreach ($today as $stat)
                        <x-stat-card :label="$stat['label']" :value="$stat['value']" :icon="$stat['icon']"
                                     :hint="$stat['hint'] ?? null" :tone="$stat['tone'] ?? 'neutral'" />
                    @endforeach

                    <x-stat-card :label="$money['held'][0]['label']" :value="App\Support\Money::rounded($money['held'][0]['paisa'])"
                                 icon="safe" :hint="$money['held'][0]['hint']" />
                </div>
            </section>
        @else
            <section x-rise>
                <h2 class="text-xs font-semibold tracking-wide text-gray-500 uppercase dark:text-gray-400">{{ __('Your day') }}</h2>

                <div class="mt-2 grid grid-cols-2 gap-2 sm:gap-3 lg:grid-cols-3">
                    @foreach ($counter as $stat)
                        <x-stat-card :label="$stat['label']" :value="$stat['value']" :icon="$stat['icon']"
                                     :hint="$stat['hint'] ?? null" :tone="$stat['tone'] ?? 'neutral'" />
                    @endforeach
                </div>
            </section>
        @endif

        <div @class(['grid gap-4', 'lg:grid-cols-3' => $financials, 'lg:grid-cols-2' => ! $financials]) x-rise="0.05">
            @include('dashboard.partials.warnings')

            @if ($financials)
                @include('dashboard.partials.money')
            @endif

            @include('dashboard.partials.drawers')
        </div>

        @if ($financials)
            <section x-rise="0.1">
                <h2 class="text-xs font-semibold tracking-wide text-gray-500 uppercase dark:text-gray-400">
                    {{ __('This month') }} <span class="font-normal normal-case">· {{ $month['label'] }}</span>
                </h2>

                <div class="mt-2 grid grid-cols-2 gap-2 sm:gap-3 lg:grid-cols-4">
                    @foreach ($month['stats'] as $stat)
                        <x-stat-card :label="$stat['label']" :value="$stat['value']" :icon="$stat['icon']"
                                     :hint="$stat['hint'] ?? null" :tone="$stat['tone'] ?? 'neutral'" />
                    @endforeach
                </div>
            </section>

            <div class="grid gap-4 lg:grid-cols-3" x-rise="0.15">
                <x-card class="lg:col-span-2" :title="__('Last 30 days')" :description="__('Net sales each day, with the daily average drawn across.')">
                    <x-slot:actions>
                        <a href="{{ route('reports.show', ['key' => 'daily-sales', 'period' => 'last_30_days']) }}"
                           class="text-xs font-semibold text-brand-700 hover:underline dark:text-brand-400">{{ __('Day by day') }}</a>
                    </x-slot:actions>

                    @if ($trend)
                        <x-chart :spec="$trend" :label="__('Net sales over the last 30 days')" />
                    @else
                        <x-empty-state icon="chart" :title="__('No sales yet')"
                                       :description="__('Once bills are rung up, each day\'s takings are drawn here.')" />
                    @endif
                </x-card>

                <x-card :title="__('Sales by category')" :description="__('This month, before GST.')">
                    @if ($categoryMix)
                        <x-chart :spec="$categoryMix" :label="__('Sales by category this month')" height="h-72" />
                    @else
                        <x-empty-state icon="layers" :title="__('Nothing sold this month')" />
                    @endif
                </x-card>
            </div>

            <x-card :title="__('Best sellers this month')" :description="__('The ten products that brought in the most, and what each one earned.')" x-rise="0.2">
                <x-slot:actions>
                    <a href="{{ route('reports.show', ['key' => 'profit', 'sort' => 'sales']) }}"
                       class="text-xs font-semibold text-brand-700 hover:underline dark:text-brand-400">{{ __('Every product') }}</a>
                </x-slot:actions>

                @if ($topProducts)
                    <x-chart :spec="$topProducts" :label="__('Best sellers this month')" height="h-80" />
                @else
                    <x-empty-state icon="box" :title="__('Nothing sold this month')"
                                   :description="__('The products that sell the most will be listed here.')" />
                @endif
            </x-card>
        @endif
    </div>
</x-app-layout>
