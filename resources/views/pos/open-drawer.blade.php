@php
    $suggested = $suggestedFloatPaisa > 0 ? rtrim(rtrim(number_format($suggestedFloatPaisa / 100, 2, '.', ''), '0'), '.') : '';
@endphp

<x-app-layout :title="__('Open the drawer')">
    <x-flash />

    <x-page-header :title="__('Open the :register drawer', ['register' => $register->name])"
                   :description="__('Before the first sale, count the change in the drawer and write it down. The end-of-shift count is checked against it.')" />

    <div class="mt-5 max-w-md">
        <form method="POST" action="{{ route('drawer.open') }}"
              class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
            @csrf
            <input type="hidden" name="register_id" value="{{ $register->id }}">
            <input type="hidden" name="back_to" value="pos">

            <div class="flex items-center gap-3">
                <span class="grid h-11 w-11 shrink-0 place-items-center rounded-lg bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-400">
                    <x-icon name="wallet" class="h-5.5 w-5.5" />
                </span>
                <div class="min-w-0">
                    <p class="text-base font-semibold">{{ $register->name }}</p>
                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Opened by :name', ['name' => auth()->user()->name]) }}</p>
                </div>
            </div>

            <div class="mt-4">
                <x-field name="float" :label="__('Cash in the drawer now (Rs.)')" required
                         :hint="$suggestedFloatPaisa > 0
                             ? __('The last shift left :amount behind. Count it — if it is different, type what you actually have.', ['amount' => \App\Support\Money::withSymbol($suggestedFloatPaisa)])
                             : __('Type 0 if the drawer is empty.')">
                    <input id="float" name="float" type="text" inputmode="decimal" required autocomplete="off" autofocus
                           value="{{ old('float', $suggested) }}"
                           class="tap-target block w-full rounded-md border-gray-300 text-lg font-semibold shadow-xs tabular-nums focus:border-brand-500 focus:ring-brand-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                </x-field>
            </div>

            <div class="mt-3">
                <x-field name="note" :label="__('Note')">
                    <input id="note" name="note" maxlength="255" autocomplete="off" value="{{ old('note') }}"
                           placeholder="{{ __('Optional') }}"
                           class="tap-target block w-full rounded-md border-gray-300 text-sm shadow-xs focus:border-brand-500 focus:ring-brand-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                </x-field>
            </div>

            <button type="submit"
                    class="tap-target mt-4 inline-flex w-full items-center justify-center gap-1.5 rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white hover:bg-brand-700">
                <x-icon name="unlock" class="h-4.5 w-4.5" />
                {{ __('Open the drawer and start selling') }}
            </button>
        </form>

        @if ($registers->count() > 1)
            <form method="POST" action="{{ route('pos.register') }}" class="mt-4 flex items-center gap-2 text-sm">
                @csrf
                <label for="switch-register" class="text-gray-600 dark:text-gray-400">{{ __('Wrong counter?') }}</label>
                <select id="switch-register" name="register_id" x-data x-on:change="$el.form.requestSubmit()"
                        class="tap-target rounded-md border-gray-300 py-0 text-sm shadow-xs dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                    @foreach ($registers as $option)
                        <option value="{{ $option->id }}" @selected($option->is($register))>{{ $option->name }}</option>
                    @endforeach
                </select>
                <noscript><button type="submit" class="font-medium text-brand-700">{{ __('Switch') }}</button></noscript>
            </form>
        @endif

        <p class="mt-4 text-sm">
            <a href="{{ route('drawer.index') }}" class="font-medium text-brand-700 hover:underline dark:text-brand-400">{{ __('See every drawer') }}</a>
        </p>
    </div>
</x-app-layout>
